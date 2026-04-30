<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\ActionPlanRepository;
use Infrastructure\Persistence\SessionComparisonRepository;
use Infrastructure\Persistence\ProviderRepository;
use Domain\Orchestration\PromptBuilder;
use Domain\Providers\ProviderFactory;

class SessionComparisonController {
    private SessionRepository        $sessionRepo;
    private MessageRepository        $messageRepo;
    private VerdictRepository        $verdictRepo;
    private ActionPlanRepository     $planRepo;
    private SessionComparisonRepository $compRepo;
    private ProviderRepository       $providerRepo;
    private PromptBuilder            $promptBuilder;

    public function __construct() {
        $this->sessionRepo   = new SessionRepository();
        $this->messageRepo   = new MessageRepository();
        $this->verdictRepo   = new VerdictRepository();
        $this->planRepo      = new ActionPlanRepository();
        $this->compRepo      = new SessionComparisonRepository();
        $this->providerRepo  = new ProviderRepository();
        $this->promptBuilder = new PromptBuilder();
    }

    public function index(Request $req): array {
        return ['comparisons' => $this->compRepo->findAll()];
    }

    public function show(Request $req): array {
        $id   = $req->param('id');
        $comp = $this->compRepo->findById($id);
        if (!$comp) return Response::error('Comparison not found', 404);
        return ['comparison' => $comp];
    }

    public function create(Request $req): array {
        $data       = $req->body();
        $sessionIds = $data['session_ids'] ?? [];
        $title      = $data['title'] ?? '';

        if (count($sessionIds) < 2 || count($sessionIds) > 4) {
            return Response::error('Select between 2 and 4 sessions', 400);
        }

        // Load all selected sessions with their data
        $sessionsData = [];
        foreach ($sessionIds as $sid) {
            $session = $this->sessionRepo->findById($sid);
            if (!$session) continue;

            $messages = $this->messageRepo->findBySession($sid);
            $verdict  = $this->verdictRepo->findBySession($sid);
            $plan     = $this->planRepo->findBySession($sid);

            // Get synthesizer content
            $synthesis = '';
            foreach ($messages as $msg) {
                if (($msg['agent_id'] ?? '') === 'synthesizer') {
                    $synthesis = $msg['content'];
                }
            }

            $sessionsData[] = array_merge($session, [
                'verdict'     => $verdict,
                'action_plan' => $plan,
                'synthesis'   => $synthesis,
            ]);
        }

        if (count($sessionsData) < 2) {
            return Response::error('Could not load enough sessions', 400);
        }

        $language = $sessionsData[0]['language'] ?? 'en';

        $markdown = null;
        try {
            $providerData = $this->resolveProvider($data['provider_id'] ?? null);
            if ($providerData) {
                $model    = $data['model'] ?? $providerData['default_model'];
                $provider = ProviderFactory::create($providerData);
                $msgs     = $this->promptBuilder->buildComparisonMessages($sessionsData, $language);
                $markdown = $provider->chat($msgs, $model);
            }
        } catch (\Throwable $e) {
            // Fall through to deterministic fallback
        }

        if (!$markdown) {
            $markdown = $this->buildDeterministicComparison($sessionsData, $language);
        }

        if (!$title) {
            $names  = array_map(fn($s) => $s['title'] ?? 'Untitled', $sessionsData);
            $title  = implode(' vs ', $names);
        }

        $now  = date('c');
        $comp = $this->compRepo->create([
            'id'               => $this->uuid(),
            'title'            => $title,
            'session_ids'      => $sessionIds,
            'content_markdown' => $markdown,
            'content_json'     => json_encode(['sessions' => array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title']], $sessionsData)]),
            'created_at'       => $now,
        ]);

        return ['comparison' => $comp];
    }

    public function destroy(Request $req): array {
        $id   = $req->param('id');
        $comp = $this->compRepo->findById($id);
        if (!$comp) return Response::error('Comparison not found', 404);
        $this->compRepo->delete($id);
        return ['success' => true, 'deleted_id' => $id];
    }

    private function buildDeterministicComparison(array $sessions, string $language): string {
        $isFr = $language === 'fr';
        $md   = $isFr ? "# Comparaison de sessions\n\n" : "# Session Comparison\n\n";
        $md  .= $isFr ? "## Sessions comparées\n\n" : "## Compared Sessions\n\n";

        foreach ($sessions as $i => $s) {
            $md .= "**Session " . ($i + 1) . ":** " . ($s['title'] ?? 'Untitled') . "\n";
            $md .= "- Mode: " . ($s['mode'] ?? 'unknown') . "\n";
            $md .= "- Agents: " . implode(', ', (array)($s['selected_agents'] ?? [])) . "\n";
            if (!empty($s['verdict'])) {
                $md .= "- Verdict: " . $s['verdict']['verdict_label'] . "\n";
            }
            $md .= "\n";
        }

        $md .= $isFr ? "## Points communs\n\n" : "## Common Points\n\n";
        $md .= $isFr
            ? "Toutes les sessions partagent le même contexte de décision. Une analyse LLM est recommandée pour une comparaison approfondie.\n\n"
            : "All sessions share the same decision context. An LLM analysis is recommended for a deeper comparison.\n\n";

        $md .= $isFr ? "## Différences clés\n\n" : "## Key Differences\n\n";
        foreach ($sessions as $i => $s) {
            $mode = $s['mode'] ?? 'unknown';
            $md  .= "- Session " . ($i + 1) . " (" . ($s['title'] ?? '') . "): mode " . $mode . "\n";
        }
        $md .= "\n";

        $md .= $isFr ? "## Risques par session\n\n" : "## Risks By Session\n\n";
        foreach ($sessions as $i => $s) {
            $score = isset($s['verdict']['risk_score']) ? $s['verdict']['risk_score'] . "/10" : "N/A";
            $md   .= "- Session " . ($i + 1) . ": Risk " . $score . "\n";
        }
        $md .= "\n";

        $md .= $isFr ? "## Meilleure option\n\n" : "## Best Option\n\n";
        $md .= $isFr
            ? "Configurez un provider LLM pour obtenir une recommandation automatique.\n\n"
            : "Configure an LLM provider to get an automatic recommendation.\n\n";

        $md .= $isFr ? "## Recommandation\n\n" : "## Recommendation\n\n";
        $md .= $isFr
            ? "Relancez la comparaison avec un provider LLM pour une analyse complète.\n\n"
            : "Re-run the comparison with an LLM provider for a full analysis.\n\n";

        $md .= $isFr ? "## Verdict final\n\n" : "## Final Verdict\n\n";
        $md .= $isFr
            ? "Analyse manuelle requise — aucun provider LLM disponible.\n"
            : "Manual analysis required — no LLM provider available.\n";

        return $md;
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
