<?php
declare(strict_types=1);

namespace Domain\Orchestration;

/**
 * Timeouts HTTP LLM et mur d’orchestration pour les modes d’analyse.
 * Les valeurs sont centralisées ici (éviter les magiques numbers dispersés).
 */
final class RunTimeoutPolicy
{
    public const LONG_MODE_TIMEOUT_SECONDS = 1800;
    public const QUICK_MODE_TIMEOUT_SECONDS = 600;
    public const CHAT_TIMEOUT_SECONDS = 600;
    public const PROVIDER_CONNECT_TIMEOUT_SECONDS = 10;

    /** Modes multi-appels / longs : timeout HTTP total par appel LLM. */
    private const LONG_MODES = ['jury', 'decision-room', 'confrontation', 'stress-test'];

    public static function connectTimeoutSeconds(): int
    {
        return self::PROVIDER_CONNECT_TIMEOUT_SECONDS;
    }

    /**
     * Timeout total cURL (secondes) pour un appel chat() vers le provider.
     */
    public static function llmHttpTimeoutSecondsForMode(string $mode): int
    {
        $m = strtolower(trim($mode));
        if ($m === 'quick-decision') {
            return self::QUICK_MODE_TIMEOUT_SECONDS;
        }
        if ($m === 'chat') {
            return self::CHAT_TIMEOUT_SECONDS;
        }
        if (in_array($m, self::LONG_MODES, true)) {
            return self::LONG_MODE_TIMEOUT_SECONDS;
        }
        return self::QUICK_MODE_TIMEOUT_SECONDS;
    }

    /**
     * Mur « run » pour diagnostics staleness (secondes depuis started_at du run_status).
     * Aligné sur le timeout LLM des modes longs.
     */
    public static function hardRunWallSecondsForMode(string $mode): int
    {
        return self::llmHttpTimeoutSecondsForMode($mode);
    }

    /**
     * Options ProviderRouter (run_mode + télémétrie run_events) pour un appel orchestré.
     *
     * @return array{run_mode: string, llm_telemetry: array<string, mixed>}
     */
    public static function routerOptionsForTelemetry(
        string $sessionId,
        string $runMode,
        string $orchestrationPhase,
        ?string $agentId = null,
        ?int $round = null,
        ?string $team = null
    ): array {
        $tel = [
            'session_id' => $sessionId,
            'phase' => $orchestrationPhase,
        ];
        if ($agentId !== null && $agentId !== '') {
            $tel['agent_id'] = $agentId;
        }
        if ($round !== null) {
            $tel['round'] = $round;
        }
        if ($team !== null && $team !== '') {
            $tel['team'] = $team;
        }

        return [
            'run_mode' => $runMode,
            'llm_telemetry' => $tel,
        ];
    }
}
