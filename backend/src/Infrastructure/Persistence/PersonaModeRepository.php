<?php
namespace Infrastructure\Persistence;

class PersonaModeRepository {
    private \PDO $pdo;

    private const ALL_MODES = ['chat', 'decision-room', 'confrontation', 'quick-decision', 'stress-test'];

    /** IDs that default to DR+Confrontation only when no explicit record exists */
    private const SYNTHESIS_IDS = ['synthesizer'];

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    /**
     * Returns mode visibility for all known persona IDs.
     * Returns [ personaId => ['chat', 'decision-room', 'confrontation', 'quick-decision'] ]
     */
    public function findAll(): array {
        $stmt = $this->pdo->query(
            "SELECT persona_id, mode, enabled FROM persona_mode_visibility"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            if ((int)$row['enabled']) {
                $map[$row['persona_id']][] = $row['mode'];
            } else {
                if (!isset($map[$row['persona_id']])) {
                    $map[$row['persona_id']] = [];
                }
            }
        }
        return $map;
    }

    /**
     * Get enabled modes for a single persona. Returns defaults if not configured.
     */
    public function getForPersona(string $personaId): array {
        $stmt = $this->pdo->prepare(
            "SELECT mode, enabled FROM persona_mode_visibility WHERE persona_id = ?"
        );
        $stmt->execute([$personaId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return $this->getDefault($personaId);
        }

        return array_values(array_map(
            fn($r) => $r['mode'],
            array_filter($rows, fn($r) => (int)$r['enabled'])
        ));
    }

    /**
     * Save mode visibility for a persona. Replaces any existing records.
     */
    public function saveForPersona(string $personaId, array $enabledModes): void {
        $this->pdo->prepare(
            "DELETE FROM persona_mode_visibility WHERE persona_id = ?"
        )->execute([$personaId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO persona_mode_visibility (persona_id, mode, enabled) VALUES (?, ?, ?)"
        );
        foreach (self::ALL_MODES as $mode) {
            $stmt->execute([$personaId, $mode, in_array($mode, $enabledModes, true) ? 1 : 0]);
        }
    }

    public function getDefault(string $personaId): array {
        if (in_array($personaId, self::SYNTHESIS_IDS, true)) {
            return ['decision-room', 'confrontation', 'quick-decision', 'stress-test'];
        }
        return self::ALL_MODES;
    }

    public static function allModes(): array {
        return self::ALL_MODES;
    }
}
