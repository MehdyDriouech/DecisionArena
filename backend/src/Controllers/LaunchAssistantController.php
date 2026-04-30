<?php
namespace Controllers;

use Http\Request;
use Http\Response;

class LaunchAssistantController {

    private const RECOMMENDATIONS = [
        'validate-idea' => [
            'mode'              => 'quick-decision',
            'selected_agents'   => ['analyst', 'pm', 'critic', 'synthesizer'],
            'rounds'            => 1,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Validation rapide : 1 seul tour avec analyse et verdict immédiat.",
            'explanation_en'    => "Quick validation: 1 round with analysis and immediate verdict.",
        ],
        'challenge-product' => [
            'mode'              => 'confrontation',
            'selected_agents'   => ['analyst', 'pm', 'ux-expert', 'critic', 'synthesizer'],
            'rounds'            => 3,
            'force_disagreement'=> true,
            'interaction_style' => 'agent-to-agent',
            'reply_policy'      => 'all-agents-reply',
            'explanation_fr'    => "Confrontation multi-tours avec agents se challengeant directement.",
            'explanation_en'    => "Multi-round confrontation with agents challenging each other directly.",
        ],
        'review-architecture' => [
            'mode'              => 'decision-room',
            'selected_agents'   => ['architect', 'po', 'critic', 'synthesizer'],
            'rounds'            => 2,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Revue structurée en 2 tours avec focus architecture et risques.",
            'explanation_en'    => "Structured 2-round review focusing on architecture and risks.",
        ],
        'find-risks' => [
            'mode'              => 'stress-test',
            'selected_agents'   => ['analyst', 'architect', 'pm', 'critic', 'synthesizer'],
            'rounds'            => 2,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Mode Stress Test : agents identifient les scénarios d'échec puis les mitigations.",
            'explanation_en'    => "Stress Test mode: agents identify failure scenarios then mitigations.",
        ],
        'compare-options' => [
            'mode'              => 'decision-room',
            'selected_agents'   => ['analyst', 'pm', 'architect', 'critic', 'synthesizer'],
            'rounds'            => 2,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Decision Room comparant les options. Utilisez la Comparaison de sessions pour comparer des sessions existantes.",
            'explanation_en'    => "Decision Room comparing options. Use Session Comparison to compare existing sessions.",
        ],
        'prepare-decision' => [
            'mode'              => 'decision-room',
            'selected_agents'   => ['analyst', 'pm', 'po', 'critic', 'synthesizer'],
            'rounds'            => 2,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Decision Room structurée pour préparer une décision avec verdict final.",
            'explanation_en'    => "Structured Decision Room to prepare a decision with final verdict.",
        ],
        'stress-test-idea' => [
            'mode'              => 'stress-test',
            'selected_agents'   => ['critic', 'architect', 'pm', 'ux-expert', 'synthesizer'],
            'rounds'            => 2,
            'force_disagreement'=> true,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Stress Test complet : scénarios d'échec, hypothèses faibles, critères d'arrêt.",
            'explanation_en'    => "Full Stress Test: failure scenarios, weak assumptions, kill criteria.",
        ],
        'custom' => [
            'mode'              => 'decision-room',
            'selected_agents'   => [],
            'rounds'            => 2,
            'force_disagreement'=> false,
            'interaction_style' => null,
            'reply_policy'      => null,
            'explanation_fr'    => "Configuration personnalisée — choisissez vous-même les paramètres.",
            'explanation_en'    => "Custom configuration — choose the parameters yourself.",
        ],
    ];

    public function recommend(Request $req): array {
        $data   = $req->body();
        $intent = $data['intent'] ?? 'custom';

        $rec = self::RECOMMENDATIONS[$intent] ?? self::RECOMMENDATIONS['custom'];

        $language    = $data['language'] ?? 'fr';
        $explanation = $language === 'fr'
            ? ($rec['explanation_fr'] ?? '')
            : ($rec['explanation_en'] ?? '');

        return [
            'intent'             => $intent,
            'mode'               => $rec['mode'],
            'selected_agents'    => $rec['selected_agents'],
            'rounds'             => $rec['rounds'],
            'force_disagreement' => $rec['force_disagreement'],
            'interaction_style'  => $rec['interaction_style'],
            'reply_policy'       => $rec['reply_policy'],
            'explanation'        => $explanation,
        ];
    }
}
