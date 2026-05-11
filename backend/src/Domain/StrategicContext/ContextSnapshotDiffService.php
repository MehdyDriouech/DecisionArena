<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

/**
 * Diff déterministe entre deux snapshots immuables (aucune mutation des lignes source).
 */
final class ContextSnapshotDiffService
{
    /**
     * @param array<string,mixed> $rowA
     * @param array<string,mixed> $rowB
     * @return array{beliefs:array<string,mixed>,narrative:array<string,mixed>,risks:array<string,mixed>,social:array<string,mixed>,memory_compilations:array<string,mixed>,markdown:string}
     */
    public function compareSnapshots(array $rowA, array $rowB): array
    {
        $a = $this->decodeRow($rowA);
        $b = $this->decodeRow($rowB);

        return [
            'beliefs' => $this->compareBeliefs($a['beliefs'], $b['beliefs']),
            'narrative' => $this->compareNarratives($a['narrative'], $b['narrative']),
            'risks' => $this->compareRisks($a['risks'], $b['risks']),
            'social' => $this->compareSocialDynamics($a['social'], $b['social']),
            'memory_compilations' => $this->compareMemoryCompilations($a['memory'], $b['memory']),
            'markdown' => $this->buildHumanDiffMarkdown($rowA, $rowB, $a, $b),
        ];
    }

    /** @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        return [
            'beliefs' => $this->json((string)($row['beliefs_snapshot_json'] ?? '{}')),
            'narrative' => $this->json((string)($row['strategic_narrative_json'] ?? '{}')),
            'risks' => $this->json((string)($row['risks_snapshot_json'] ?? '{}')),
            'social' => $this->json((string)($row['social_snapshot_json'] ?? '{}')),
            'memory' => $this->json((string)($row['memory_compilations_json'] ?? '{}')),
            'timeline' => $this->json((string)($row['timeline_snapshot_json'] ?? '{}')),
            'evidence' => $this->json((string)($row['evidence_snapshot_json'] ?? '{}')),
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $s): array
    {
        if ($s === '') {
            return [];
        }
        $d = json_decode($s, true);

        return is_array($d) ? $d : [];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    public function compareBeliefs(array $a, array $b): array
    {
        $idsA = $this->beliefIdsFromSnapshot($a);
        $idsB = $this->beliefIdsFromSnapshot($b);
        $added = array_values(array_diff($idsB, $idsA));
        $removed = array_values(array_diff($idsA, $idsB));
        $ca = $a['counts'] ?? [];
        $cb = $b['counts'] ?? [];
        if (!is_array($ca)) {
            $ca = [];
        }
        if (!is_array($cb)) {
            $cb = [];
        }

        return [
            'belief_ids_added' => $added,
            'belief_ids_removed' => $removed,
            'counts_delta' => [
                'total' => (int)($cb['total'] ?? 0) - (int)($ca['total'] ?? 0),
            ],
        ];
    }

    /** @param array<string,mixed> $snap @return list<string> */
    private function beliefIdsFromSnapshot(array $snap): array
    {
        $out = [];
        foreach (['dominant', 'disputed', 'emerging', 'deprecated'] as $k) {
            $xs = $snap['samples'][$k] ?? [];
            if (!is_array($xs)) {
                continue;
            }
            foreach ($xs as $it) {
                if (is_array($it) && isset($it['id'])) {
                    $out[] = (string)$it['id'];
                }
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    public function compareNarratives(array $a, array $b): array
    {
        $ha = trim((string)($a['headline_echo'] ?? ''));
        $hb = trim((string)($b['headline_echo'] ?? ''));

        return [
            'headline_changed' => $ha !== $hb,
            'headline_before' => $ha,
            'headline_after' => $hb,
            'computed_at_a' => (string)($a['computed_at'] ?? ''),
            'computed_at_b' => (string)($b['computed_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    public function compareRisks(array $a, array $b): array
    {
        $ta = $this->riskThemeSet($a);
        $tb = $this->riskThemeSet($b);

        return [
            'themes_added' => array_values(array_diff($tb, $ta)),
            'themes_removed' => array_values(array_diff($ta, $tb)),
            'profile_count_delta' => (int)($b['risk_profiles_count'] ?? 0) - (int)($a['risk_profiles_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $r @return list<string> */
    private function riskThemeSet(array $r): array
    {
        $themes = $r['timeline_risk_themes'] ?? [];
        if (!is_array($themes)) {
            return [];
        }
        $out = [];
        foreach ($themes as $t) {
            $s = mb_strtolower(trim((string)$t), 'UTF-8');
            if ($s !== '') {
                $out[] = $s;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    public function compareSocialDynamics(array $a, array $b): array
    {
        $ea = $a['event_type_counts'] ?? [];
        $eb = $b['event_type_counts'] ?? [];
        if (!is_array($ea)) {
            $ea = [];
        }
        if (!is_array($eb)) {
            $eb = [];
        }
        $keys = array_values(array_unique(array_merge(array_keys($ea), array_keys($eb))));
        sort($keys);
        $deltas = [];
        foreach ($keys as $k) {
            $d = (int)($eb[$k] ?? 0) - (int)($ea[$k] ?? 0);
            if ($d !== 0) {
                $deltas[$k] = $d;
            }
        }

        return [
            'relationship_rows_delta' => (int)($b['relationship_rows'] ?? 0) - (int)($a['relationship_rows'] ?? 0),
            'event_type_count_deltas' => $deltas,
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    public function compareMemoryCompilations(array $a, array $b): array
    {
        $idsA = $this->compilationIds($a);
        $idsB = $this->compilationIds($b);

        return [
            'compilation_ids_added' => array_values(array_diff($idsB, $idsA)),
            'compilation_ids_removed' => array_values(array_diff($idsA, $idsB)),
            'active_count_delta' => (int)($b['active_count'] ?? 0) - (int)($a['active_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $m @return list<string> */
    private function compilationIds(array $m): array
    {
        $items = $m['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $it) {
            if (is_array($it) && isset($it['id'])) {
                $out[] = (string)$it['id'];
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $rowA
     * @param array<string,mixed> $rowB
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function buildHumanDiffMarkdown(array $rowA, array $rowB, array $a, array $b): string
    {
        $lines = [];
        $lines[] = '# Context Snapshot Diff';
        $lines[] = sprintf('- **A** : `%s` · %s · %s', (string)($rowA['id'] ?? ''), (string)($rowA['snapshot_type'] ?? ''), (string)($rowA['created_at'] ?? ''));
        $lines[] = sprintf('- **B** : `%s` · %s · %s', (string)($rowB['id'] ?? ''), (string)($rowB['snapshot_type'] ?? ''), (string)($rowB['created_at'] ?? ''));
        $lines[] = '';
        $lines[] = '_Diff déterministe MVP — pas d’inférence LLM._';
        $lines[] = '';

        $bd = $this->compareBeliefs($a['beliefs'], $b['beliefs']);
        $lines[] = '## Beliefs';
        $lines[] = '- Δ total (counts) : ' . (string)($bd['counts_delta']['total'] ?? 0);
        if (($bd['belief_ids_added'] ?? []) !== []) {
            $lines[] = '- Nouveaux IDs (échantillon) : `' . implode('`, `', array_slice($bd['belief_ids_added'], 0, 12)) . '`';
        }
        if (($bd['belief_ids_removed'] ?? []) !== []) {
            $lines[] = '- IDs absents dans B (échantillon) : `' . implode('`, `', array_slice($bd['belief_ids_removed'], 0, 12)) . '`';
        }
        $lines[] = '';

        $nd = $this->compareNarratives($a['narrative'], $b['narrative']);
        $lines[] = '## Narrative';
        $lines[] = '- Changement headline : ' . (($nd['headline_changed'] ?? false) ? 'oui' : 'non');
        if (($nd['headline_changed'] ?? false)) {
            $lines[] = '- A : ' . mb_substr((string)($nd['headline_before'] ?? ''), 0, 200, 'UTF-8');
            $lines[] = '- B : ' . mb_substr((string)($nd['headline_after'] ?? ''), 0, 200, 'UTF-8');
        }
        $lines[] = '';

        $rd = $this->compareRisks($a['risks'], $b['risks']);
        $lines[] = '## Risks';
        $lines[] = '- Δ risk_profiles_count : ' . (string)($rd['profile_count_delta'] ?? 0);
        if (($rd['themes_added'] ?? []) !== []) {
            $lines[] = '- Thèmes timeline ajoutés : ' . implode(', ', array_slice($rd['themes_added'], 0, 8));
        }
        if (($rd['themes_removed'] ?? []) !== []) {
            $lines[] = '- Thèmes timeline retirés : ' . implode(', ', array_slice($rd['themes_removed'], 0, 8));
        }
        $lines[] = '';

        $sd = $this->compareSocialDynamics($a['social'], $b['social']);
        $lines[] = '## Social dynamics';
        $lines[] = '- Δ relationship_rows : ' . (string)($sd['relationship_rows_delta'] ?? 0);
        $edc = $sd['event_type_count_deltas'] ?? [];
        if (is_array($edc) && $edc !== []) {
            $lines[] = '- Δ comptages event_type : ' . json_encode($edc, JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';

        $md = $this->compareMemoryCompilations($a['memory'], $b['memory']);
        $lines[] = '## Memory compilations';
        $lines[] = '- Δ active_count : ' . (string)($md['active_count_delta'] ?? 0);
        if (($md['compilation_ids_added'] ?? []) !== []) {
            $lines[] = '- Compilations présentes dans B : `' . implode('`, `', array_slice($md['compilation_ids_added'], 0, 8)) . '`';
        }
        $lines[] = '';

        $lines[] = '## Timeline (volumes)';
        $ta = $a['timeline']['counts_by_type'] ?? [];
        $tb = $b['timeline']['counts_by_type'] ?? [];
        if (is_array($ta) && is_array($tb)) {
            $keys = array_values(array_unique(array_merge(array_keys($ta), array_keys($tb))));
            sort($keys);
            foreach ($keys as $k) {
                $d = (int)($tb[$k] ?? 0) - (int)($ta[$k] ?? 0);
                if ($d !== 0) {
                    $lines[] = sprintf('- **%s** : %d → %d (Δ %d)', $k, (int)($ta[$k] ?? 0), (int)($tb[$k] ?? 0), $d);
                }
            }
        }

        return implode("\n", $lines);
    }
}
