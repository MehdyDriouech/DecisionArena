<?php
namespace Controllers;

use Http\Request;
use Domain\DecisionReliability\ContextQualityAnalyzer;
use Domain\DecisionReliability\ContextClarificationService;

class ContextCheckController {

    public function check(Request $req): array {
        $data      = $req->body();
        $objective = trim((string)($data['objective'] ?? ''));

        if ($objective === '') {
            http_response_code(400);
            return ['ok' => false, 'error' => 'objective is required'];
        }

        $analyzer     = new ContextQualityAnalyzer();
        $clarifier    = new ContextClarificationService();

        $analysis     = $analyzer->analyze($objective);
        $clarification = $clarifier->generateClarificationQuestions($analysis);

        return [
            'ok'        => true,
            'analysis'  => $analysis,
            'questions' => $clarification['questions'],
        ];
    }
}
