<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\Database;
use Infrastructure\Logging\Logger;

class LogsController {
    private \PDO $pdo;
    private Logger $logger;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
        $this->logger = new Logger();
    }

    public function index(Request $req): array {
        $level     = trim((string)$req->get('level', ''));
        $category  = trim((string)$req->get('category', ''));
        $sessionId = trim((string)$req->get('session_id', ''));
        $providerId= trim((string)$req->get('provider_id', ''));
        $agentId   = trim((string)$req->get('agent_id', ''));
        $from      = trim((string)$req->get('from', ''));
        $to        = trim((string)$req->get('to', ''));
        $search    = trim((string)$req->get('search', ''));
        $limit     = (int)$req->get('limit', 100);
        $offset    = (int)$req->get('offset', 0);

        $limit  = min(max($limit, 1), 500);
        $offset = max($offset, 0);

        $where = [];
        $params = [];

        if ($level !== '') { $where[] = 'level = :level'; $params[':level'] = strtolower($level); }
        if ($category !== '') { $where[] = 'category = :category'; $params[':category'] = strtolower($category); }
        if ($sessionId !== '') { $where[] = 'session_id = :session_id'; $params[':session_id'] = $sessionId; }
        if ($providerId !== '') { $where[] = 'provider_id = :provider_id'; $params[':provider_id'] = $providerId; }
        if ($agentId !== '') { $where[] = 'agent_id = :agent_id'; $params[':agent_id'] = $agentId; }
        if ($from !== '') { $where[] = 'created_at >= :from'; $params[':from'] = $from; }
        if ($to !== '') { $where[] = 'created_at <= :to'; $params[':to'] = $to; }
        if ($search !== '') {
            $where[] = "(action LIKE :q OR error_message LIKE :q OR request_payload LIKE :q OR response_payload LIKE :q OR metadata LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        $sql = 'SELECT id, level, category, session_id, message_id, provider_id, model, agent_id, action, error_message, created_at
                FROM app_logs';
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'logs' => $logs,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(Request $req): array {
        $id = $req->param('id');
        if (!$id) return Response::error('log id required', 400);
        $stmt = $this->pdo->prepare('SELECT * FROM app_logs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Response::error('Log not found', 404);
        return ['log' => $row];
    }

    public function frontend(Request $req): array {
        $data = $req->body();
        $level = (string)($data['level'] ?? 'info');
        $category = (string)($data['category'] ?? 'frontend');

        $payload = [
            'level' => $level,
            'category' => $category,
            'action' => $data['action'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'model' => $data['model'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'error_message' => $data['error_message'] ?? null,
        ];

        $this->logger->logFrontendEvent($payload);
        return ['success' => true];
    }

    public function delete(Request $req): array {
        $data = $req->body();
        $older = isset($data['older_than_days']) ? (int)$data['older_than_days'] : null;

        if ($older !== null && $older > 0) {
            $cutoff = (new \DateTimeImmutable('now'))->modify('-' . $older . ' days')->format('c');
            $stmt = $this->pdo->prepare('DELETE FROM app_logs WHERE created_at < ?');
            $stmt->execute([$cutoff]);
            return ['success' => true, 'deleted' => $stmt->rowCount(), 'cutoff' => $cutoff];
        }

        $confirm = (string)($data['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            return Response::error('To delete all logs, pass confirm="DELETE".', 400);
        }
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM app_logs')->fetchColumn();
        $this->pdo->exec('DELETE FROM app_logs');
        return ['success' => true, 'deleted' => $count];
    }

    public function export(Request $req): array {
        $data = $req->body();
        $format = (string)($data['format'] ?? 'json');
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];

        $level = trim((string)($filters['level'] ?? ''));
        $category = trim((string)($filters['category'] ?? ''));
        $sessionId = trim((string)($filters['session_id'] ?? ''));
        $providerId = trim((string)($filters['provider_id'] ?? ''));
        $agentId = trim((string)($filters['agent_id'] ?? ''));
        $from = trim((string)($filters['from'] ?? ''));
        $to = trim((string)($filters['to'] ?? ''));
        $search = trim((string)($filters['search'] ?? ''));

        $where = [];
        $params = [];
        if ($level !== '') { $where[] = 'level = :level'; $params[':level'] = strtolower($level); }
        if ($category !== '') { $where[] = 'category = :category'; $params[':category'] = strtolower($category); }
        if ($sessionId !== '') { $where[] = 'session_id = :session_id'; $params[':session_id'] = $sessionId; }
        if ($providerId !== '') { $where[] = 'provider_id = :provider_id'; $params[':provider_id'] = $providerId; }
        if ($agentId !== '') { $where[] = 'agent_id = :agent_id'; $params[':agent_id'] = $agentId; }
        if ($from !== '') { $where[] = 'created_at >= :from'; $params[':from'] = $from; }
        if ($to !== '') { $where[] = 'created_at <= :to'; $params[':to'] = $to; }
        if ($search !== '') {
            $where[] = "(action LIKE :q OR error_message LIKE :q OR request_payload LIKE :q OR response_payload LIKE :q OR metadata LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        $sql = 'SELECT * FROM app_logs';
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($format === 'markdown') {
            $md = "# Decision Arena Logs Export\n\n";
            $md .= "> Logs may contain prompt content and model outputs. Do not share them publicly.\n\n";
            foreach ($rows as $r) {
                $md .= "## " . ($r['created_at'] ?? '') . " — " . strtoupper((string)($r['level'] ?? '')) . " — " . ($r['category'] ?? '') . "\n\n";
                $md .= "- **id:** " . ($r['id'] ?? '') . "\n";
                if (!empty($r['session_id'])) $md .= "- **session:** " . $r['session_id'] . "\n";
                if (!empty($r['agent_id'])) $md .= "- **agent:** " . $r['agent_id'] . "\n";
                if (!empty($r['provider_id'])) $md .= "- **provider:** " . $r['provider_id'] . "\n";
                if (!empty($r['model'])) $md .= "- **model:** " . $r['model'] . "\n";
                if (!empty($r['action'])) $md .= "- **action:** " . $r['action'] . "\n";
                if (!empty($r['error_message'])) $md .= "- **error:** " . $r['error_message'] . "\n";
                $md .= "\n";
                if (!empty($r['request_payload'])) {
                    $md .= "### Request payload\n\n```json\n" . $r['request_payload'] . "\n```\n\n";
                }
                if (!empty($r['response_payload'])) {
                    $md .= "### Response payload\n\n```json\n" . $r['response_payload'] . "\n```\n\n";
                }
                if (!empty($r['metadata'])) {
                    $md .= "### Metadata\n\n```json\n" . $r['metadata'] . "\n```\n\n";
                }
                $md .= "---\n\n";
            }
            return [
                'format' => 'markdown',
                'content' => $md,
                'filename' => 'logs-export.md',
            ];
        }

        return [
            'format' => 'json',
            'logs' => $rows,
            'filename' => 'logs-export.json',
        ];
    }
}

