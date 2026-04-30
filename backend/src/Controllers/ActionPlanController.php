<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\ActionPlanRepository;
use Infrastructure\Persistence\ProviderRepository;
use Domain\Orchestration\PromptBuilder;
use Domain\Providers\ProviderFactory;

class ActionPlanController {
    private SessionRepository    $sessionRepo;
    private MessageRepository    $messageRepo;
    private VerdictRepository    $verdictRepo;
    private ActionPlanRepository $planRepo;
    private ProviderRepository   $providerRepo;
    private PromptBuilder        $promptBuilder;

    public function __construct() {
        $this->sessionRepo   = new SessionRepository();
        $this->messageRepo   = new MessageRepository();
        $this->verdictRepo   = new VerdictRepository();
        $this->planRepo      = new ActionPlanRepository();
        $this->providerRepo  = new ProviderRepository();
        $this->promptBuilder = new PromptBuilder();
    }

    public function show(Request $req): array {
        $id      = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) return Response::error('Session not found', 404);

        $plan = $this->planRepo->findBySession($id);
        return ['action_plan' => $plan];
    }

    public function generate(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();

        $session = $this->sessionRepo->findById($id);
        if (!$session) return Response::error('Session not found', 404);

        $messages = $this->messageRepo->findBySession($id);
        $verdict  = $this->verdictRepo->findBySession($id);
        $language = $session['language'] ?? 'en';

        // Build session content from synthesis and verdict
        $sessionContent = $this->buildSessionContent($session, $messages, $verdict);

        // Try to use LLM
        $plan = null;
        try {
            $providerData = $this->resolveProvider($data['provider_id'] ?? null);
            if ($providerData) {
                $model    = $data['model'] ?? $providerData['default_model'];
                $provider = ProviderFactory::create($providerData);
                $msgs     = $this->promptBuilder->buildActionPlanMessages($sessionContent, $language);
                $raw      = $provider->chat($msgs, $model);

                // Strip markdown code fences if present
                $raw = preg_replace('/^```json\s*/i', '', trim($raw));
                $raw = preg_replace('/\s*```$/', '', $raw);

                $parsed = json_decode(trim($raw), true);
                if (is_array($parsed)) {
                    $plan = $parsed;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to deterministic fallback
        }

        if (!$plan) {
            $plan = $this->buildDeterministicPlan($session, $messages, $verdict, $language);
        }

        // Delete existing plan for this session before creating new one
        $existing = $this->planRepo->findBySession($id);
        if ($existing) {
            $this->deletePlanById($existing['id']);
        }

        $now  = date('c');
        $uuid = $this->uuid();
        $saved = $this->planRepo->create([
            'id'                 => $uuid,
            'session_id'         => $id,
            'source_message_id'  => null,
            'summary'            => $plan['summary'] ?? '',
            'immediate_actions'  => $plan['immediate_actions'] ?? [],
            'short_term_actions' => $plan['short_term_actions'] ?? [],
            'experiments'        => $plan['experiments'] ?? [],
            'risks_to_monitor'   => $plan['risks_to_monitor'] ?? [],
            'owner_notes'        => '',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return ['action_plan' => $saved];
    }

    public function update(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();

        $session = $this->sessionRepo->findById($id);
        if (!$session) return Response::error('Session not found', 404);

        $plan = $this->planRepo->findBySession($id);
        if (!$plan) return Response::error('No action plan found for this session', 404);

        $allowed = ['summary', 'immediate_actions', 'short_term_actions', 'experiments', 'risks_to_monitor', 'owner_notes'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (!empty($updates)) {
            $this->planRepo->update($plan['id'], $updates);
        }

        return ['action_plan' => $this->planRepo->findBySession($id)];
    }

    private function buildSessionContent(array $session, array $messages, ?array $verdict): string {
        $content = "# Session: " . ($session['title'] ?? 'Untitled') . "\n\n";
        $content .= "**Mode:** " . ($session['mode'] ?? 'unknown') . "\n";
        $content .= "**Initial Prompt:** " . ($session['initial_prompt'] ?? '') . "\n\n";

        // Include synthesis/final messages
        $synthMessages = array_filter($messages, fn($m) =>
            in_array($m['message_type'] ?? '', ['synthesis', 'analysis'])
            && ($m['agent_id'] ?? '') === 'synthesizer'
        );

        if (!empty($synthMessages)) {
            $content .= "## Synthesis\n\n";
            foreach ($synthMessages as $msg) {
                $content .= $msg['content'] . "\n\n";
            }
        } elseif (!empty($messages)) {
            // Fall back to last few messages
            $last = array_slice($messages, -5);
            $content .= "## Recent Analysis\n\n";
            foreach ($last as $msg) {
                $content .= "**[" . ($msg['agent_id'] ?? 'Agent') . "]:** " . $msg['content'] . "\n\n";
            }
        }

        if ($verdict) {
            $content .= "## Verdict\n\n";
            $content .= "**Label:** " . ($verdict['verdict_label'] ?? '') . "\n";
            $content .= "**Summary:** " . ($verdict['verdict_summary'] ?? '') . "\n";
            if (!empty($verdict['recommended_action'])) {
                $content .= "**Recommended Action:** " . $verdict['recommended_action'] . "\n";
            }
        }

        return $content;
    }

    private function buildDeterministicPlan(array $session, array $messages, ?array $verdict, string $language): array {
        $summaryBase = $verdict
            ? ($verdict['verdict_summary'] ?? 'Session completed.')
            : ('Session "' . ($session['title'] ?? '') . '" completed.');

        $isFr = $language === 'fr';

        return [
            'summary'            => $summaryBase,
            'immediate_actions'  => [
                [
                    'title'       => $isFr ? 'Revenir sur les résultats' : 'Review session results',
                    'description' => $isFr
                        ? 'Relisez attentivement les analyses des agents et identifiez les points clés.'
                        : 'Read through the agent analyses carefully and identify the key takeaways.',
                    'priority'    => 'high',
                ],
                [
                    'title'       => $isFr ? 'Partager les conclusions avec l\'équipe' : 'Share findings with the team',
                    'description' => $isFr
                        ? 'Partagez les résultats et le verdict avec les parties prenantes concernées.'
                        : 'Share the results and verdict with relevant stakeholders.',
                    'priority'    => 'medium',
                ],
            ],
            'short_term_actions' => [
                [
                    'title'       => $isFr ? 'Planifier la prochaine étape' : 'Plan next step',
                    'description' => $isFr
                        ? 'Définissez une action concrète basée sur la décision prise.'
                        : 'Define a concrete action based on the decision taken.',
                    'priority'    => 'medium',
                ],
            ],
            'experiments'        => [
                [
                    'title'          => $isFr ? 'Valider l\'hypothèse principale' : 'Validate the main hypothesis',
                    'hypothesis'     => $isFr
                        ? 'L\'idée est viable et applicable dans le contexte actuel.'
                        : 'The idea is viable and applicable in the current context.',
                    'success_metric' => $isFr ? 'Retour positif de 3 parties prenantes' : 'Positive feedback from 3 stakeholders',
                ],
            ],
            'risks_to_monitor'   => [
                [
                    'risk'       => $isFr ? 'Dérive du scope' : 'Scope creep',
                    'mitigation' => $isFr
                        ? 'Définissez des limites claires dès le départ.'
                        : 'Define clear boundaries from the start.',
                ],
            ],
        ];
    }

    private function resolveProvider(?string $providerId): ?array {
        if ($providerId) {
            $p = $this->providerRepo->findById($providerId);
            if ($p) return $p;
        }
        $all = $this->providerRepo->findAll();
        $enabled = array_values(array_filter($all, fn($p) => $p['enabled']));
        return $enabled[0] ?? null;
    }

    private function deletePlanById(string $planId): void {
        $pdo = \Infrastructure\Persistence\Database::getInstance()->pdo();
        $pdo->prepare('DELETE FROM session_action_plans WHERE id = ?')->execute([$planId]);
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
