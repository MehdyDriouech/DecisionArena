<?php
declare(strict_types=1);
namespace Infrastructure\Persistence;

use Domain\Agents\DecisionDynamics;
use Domain\Agents\DecisionDynamicsPreset;

class PersonaDecisionDynamicsRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    /** Raw JSON stored for persona, or null if no row. */
    public function findRawJson(string $personaId): ?string {
        $stmt = $this->pdo->prepare('SELECT decision_dynamics FROM persona_decision_dynamics WHERE persona_id = ?');
        $stmt->execute([$personaId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['decision_dynamics'] === null || $row['decision_dynamics'] === '') {
            return null;
        }
        return (string)$row['decision_dynamics'];
    }

    /** @return ?array<string,mixed> */
    public function findRawDecoded(string $personaId): ?array {
        $json = $this->findRawJson($personaId);
        if ($json === null) {
            return null;
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }

    /**
     * @return array<string, array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string}>
     */
    public function findAllNormalizedByPersonaId(): array {
        $stmt = $this->pdo->query('SELECT persona_id, decision_dynamics FROM persona_decision_dynamics');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $row) {
            $id = (string)($row['persona_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $d = json_decode((string)($row['decision_dynamics'] ?? '{}'), true);
            $map[$id] = DecisionDynamics::normalize(is_array($d) ? $d : null);
        }
        return $map;
    }

    /**
     * Merge order: defaults &lt; optional frontmatter &lt; DB row.
     *
     * @param ?array<string,mixed> $frontMatterDynamics
     * @return array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string}
     */
    public function resolveForPersona(string $personaId, ?array $frontMatterDynamics = null): array {
        $db = $this->findRawDecoded($personaId);
        $merged = array_merge(
            DecisionDynamics::defaults(),
            is_array($frontMatterDynamics) ? $frontMatterDynamics : [],
            is_array($db) ? $db : []
        );
        return DecisionDynamics::normalize($merged);
    }

    /**
     * Stored persona dynamics + optional session preset overlay (never written to persona_decision_dynamics).
     *
     * @return array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string}
     */
    public function resolveEffectiveForPersona(string $personaId, ?array $frontMatterDynamics, ?string $sessionPresetId): array {
        $base = $this->resolveForPersona($personaId, $frontMatterDynamics);
        return DecisionDynamicsPreset::applyToBaseline($base, $sessionPresetId);
    }

    /**
     * @param array<string,mixed> $dynamics
     */
    public function save(string $personaId, array $dynamics): array {
        $personaId = preg_replace('/[^a-z0-9\-]/', '', $personaId);
        if ($personaId === '') {
            throw new \InvalidArgumentException('persona_id is required');
        }
        $norm = DecisionDynamics::normalize($dynamics);
        $json = json_encode($norm, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('
            INSERT INTO persona_decision_dynamics (persona_id, decision_dynamics, updated_at)
            VALUES (?, ?, ?)
            ON CONFLICT(persona_id) DO UPDATE SET
                decision_dynamics = excluded.decision_dynamics,
                updated_at = excluded.updated_at
        ');
        $now = date('c');
        $stmt->execute([$personaId, $json, $now]);
        return $norm;
    }

    /**
     * Snapshot for audit / session payloads: one row per agent id.
     * When sessionPresetId is set, dynamics/reputation reflect the session overlay; baseline_dynamics stays the stored admin config.
     *
     * @param list<string|mixed> $agentIds
     * @return list<array<string,mixed>>
     */
    public function transparencyForAgents(array $agentIds, ?string $sessionPresetId = null): array {
        $preset = DecisionDynamicsPreset::normalizeId($sessionPresetId);
        $out    = [];
        foreach ($agentIds as $raw) {
            $id = preg_replace('/[^a-z0-9\-]/', '', (string)$raw);
            if ($id === '') {
                continue;
            }
            $baseline = $this->resolveForPersona($id, null);
            $d        = DecisionDynamicsPreset::applyToBaseline($baseline, $preset);
            $out[]    = [
                'agent'                         => $id,
                'session_decision_dynamics_preset'=> $preset,
                'baseline_dynamics'           => $baseline,
                'reputation'                   => $d['reputation'],
                'dynamics'                   => $d,
                'readable'                     => sprintf(
                    '%s — réputation ×%.2f | consensus: %s | preuves: %s | risque: %s',
                    $id,
                    $d['reputation'],
                    $d['consensus_resistance'],
                    $d['evidence_sensitivity'],
                    $d['risk_tolerance']
                ),
            ];
        }
        return $out;
    }

    /**
     * Agent IDs from session.selected_agents, or inferred from votes when roster is missing (sessions legacy).
     *
     * @param array<string,mixed> $session
     * @param list<array<string,mixed>> $votes
     * @return list<string>
     */
    public function agentIdsFromSessionAndVotes(array $session, array $votes): array {
        $agentIdsRaw = $session['selected_agents'] ?? [];
        if (is_string($agentIdsRaw)) {
            $decodedAgents = json_decode($agentIdsRaw, true);
            $agentIdsRaw   = is_array($decodedAgents) ? $decodedAgents : [];
        }
        if (!is_array($agentIdsRaw)) {
            $agentIdsRaw = [];
        }
        $ids = [];
        foreach ($agentIdsRaw as $raw) {
            $id = preg_replace('/[^a-z0-9\-]/', '', (string)$raw);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            return $ids;
        }
        foreach ($votes as $v) {
            $id = preg_replace('/[^a-z0-9\-]/', '', (string)($v['agent_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $session
     * @param list<array<string,mixed>> $votes
     * @return list<array<string,mixed>>
     */
    public function transparencyForSession(array $session, array $votes): array {
        $ids = $this->agentIdsFromSessionAndVotes($session, $votes);

        return $this->transparencyForAgents($ids, $session['decision_dynamics_preset'] ?? null);
    }
}
