<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

$pdo = \Infrastructure\Persistence\Database::getConnection();

// Add is_system_template column if missing
try {
    $pdo->exec("ALTER TABLE scenario_packs ADD COLUMN is_system_template INTEGER NOT NULL DEFAULT 0");
    echo "Added is_system_template column\n";
} catch (\Exception $e) {
    echo "Column already exists (OK)\n";
}

$templates = [
    [
        'id'                       => 'tpl-ship-or-not',
        'title'                    => 'Ship or not',
        'description'              => 'Should we ship this feature/product now?',
        'mode'                     => 'decision-room',
        'selected_agents'          => json_encode(['pm', 'architect', 'critic', 'ux-expert']),
        'rounds'                   => 2,
        'force_disagreement'       => 1,
        'devil_advocate_enabled'   => 1,
        'auto_retry_on_weak_debate'=> 1,
        'context_questions'        => json_encode(['What is the target launch date?', 'What are the success metrics?', 'What is the current technical debt level?']),
        'is_system_template'       => 1,
    ],
    [
        'id'                       => 'tpl-refactor-vs-ship',
        'title'                    => 'Refactor vs Ship',
        'description'              => 'Should we refactor first or ship as-is?',
        'mode'                     => 'confrontation',
        'selected_agents'          => json_encode(['architect', 'critic', 'pm']),
        'rounds'                   => 3,
        'force_disagreement'       => 1,
        'devil_advocate_enabled'   => 0,
        'auto_retry_on_weak_debate'=> 1,
        'context_questions'        => json_encode(['What is the current pain level for users?', 'What is the user impact of the tech debt?', 'What is the estimated refactor effort?']),
        'is_system_template'       => 1,
    ],
    [
        'id'                       => 'tpl-build-vs-buy',
        'title'                    => 'Build vs Buy',
        'description'              => 'Should we build this internally or buy a solution?',
        'mode'                     => 'jury',
        'selected_agents'          => json_encode(['pm', 'architect', 'critic']),
        'rounds'                   => 2,
        'force_disagreement'       => 1,
        'devil_advocate_enabled'   => 1,
        'auto_retry_on_weak_debate'=> 1,
        'context_questions'        => json_encode(['What is the budget?', 'What is the timeline?', 'Is this a core competency?']),
        'is_system_template'       => 1,
    ],
    [
        'id'                       => 'tpl-hire-or-not',
        'title'                    => 'Hire or not',
        'description'              => 'Should we make this hire?',
        'mode'                     => 'decision-room',
        'selected_agents'          => json_encode(['pm', 'critic']),
        'rounds'                   => 2,
        'force_disagreement'       => 0,
        'devil_advocate_enabled'   => 0,
        'auto_retry_on_weak_debate'=> 0,
        'context_questions'        => json_encode(['What is the role scope?', 'What is the budget?', 'What is the current team workload?']),
        'is_system_template'       => 1,
    ],
    [
        'id'                       => 'tpl-launch-or-wait',
        'title'                    => 'Launch or wait',
        'description'              => 'Are we ready to launch, or should we wait?',
        'mode'                     => 'decision-room',
        'selected_agents'          => json_encode(['pm', 'ux-expert', 'architect', 'critic']),
        'rounds'                   => 2,
        'force_disagreement'       => 1,
        'devil_advocate_enabled'   => 1,
        'auto_retry_on_weak_debate'=> 1,
        'context_questions'        => json_encode(['Is the market ready?', 'What are the main risks?', 'What are the go/no-go metrics?']),
        'is_system_template'       => 1,
    ],
];

$stmt = $pdo->prepare(
    "INSERT OR IGNORE INTO scenario_packs
     (id, title, description, mode, selected_agents, rounds, force_disagreement,
      devil_advocate_enabled, auto_retry_on_weak_debate, context_questions, is_system_template)
     VALUES
     (:id, :title, :description, :mode, :selected_agents, :rounds, :force_disagreement,
      :devil_advocate_enabled, :auto_retry_on_weak_debate, :context_questions, :is_system_template)"
);

foreach ($templates as $tpl) {
    $stmt->execute($tpl);
    echo "Seeded: {$tpl['title']}\n";
}
echo "Done.\n";
