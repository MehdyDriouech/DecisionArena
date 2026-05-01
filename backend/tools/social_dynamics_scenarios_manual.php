<?php
/**
 * Manual validation hints for social dynamics (V1).
 * Run: php backend/tools/social_dynamics_scenarios_manual.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

echo "--- Decision Arena — Social dynamics manual scenarios (V1) ---\n\n";

$scenarios = [
    'A — Conflit explicite' => [
        'input' => 'Should we launch this product now despite unclear market demand?',
        'attendu' => [
            'Événements challenge/opposition sur relationship_events',
            'Augmentation du conflict sur au moins une paire (agent_relationships)',
            'Carte UI : au moins un conflit listé après chargement analytics',
        ],
    ],
    'B — Alliance' => [
        'input' => 'Should we prioritize UX quality over delivery speed?',
        'attendu' => [
            'Possibles agents UX + PO/PM alignés (Alignment/Alliance ou inférence fallback)',
            'Événements support ou alliance',
            'Affinity en hausse sur la paire pertinente',
        ],
    ],
    'C — Faux consensus' => [
        'input' => '(Session multi-tours où tous les agents convergent trop vite)',
        'attendu' => [
            'Flag interne forceStrongNext après un tour (shouldForceChallengeNextRound)',
            'Round suivant avec consigne plus contradictoire dans le prompt',
            'Pas de round supplémentaire au-delà du max configuré',
        ],
    ],
    'D — Garde-fou' => [
        'input' => 'Même en conflit fort',
        'attendu' => [
            'Orchestrator / policies : pas d’insultes, pas d’attaques personnelles',
            'Réponses restent sur le fond (raisonnement)',
            'Pas de boucle autonome multi-tours hors contrôle utilisateur',
        ],
    ],
];

foreach ($scenarios as $title => $body) {
    echo "### {$title}\n";
    echo "Prompt exemple: {$body['input']}\n";
    foreach ($body['attendu'] as $line) {
        echo " - {$line}\n";
    }
    echo "\n";
}
