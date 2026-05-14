<?php
namespace Infrastructure\Persistence;

class ProviderRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findAll(): array {
        $stmt = $this->pdo->query('SELECT * FROM providers ORDER BY created_at DESC');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findEnabledOrdered(): array {
        // Lower priority number == higher priority
        $stmt = $this->pdo->query("
            SELECT * FROM providers
            WHERE enabled = 1
            ORDER BY COALESCE(priority, 100) ASC, created_at ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Règles alignées avec le client : activé, base URL si requis, clé si openai-compatible, priorité bornée.
     */
    public function rowIsRoutingEligible(array $p): bool {
        if ((int)($p['enabled'] ?? 0) !== 1) {
            return false;
        }
        $type = strtolower((string)($p['type'] ?? ''));
        $base = trim((string)($p['base_url'] ?? ''));
        $key = trim((string)($p['api_key'] ?? ''));
        if ($type === 'ollama') {
            return $base !== '';
        }
        if ($type === 'lmstudio') {
            return $base !== '';
        }
        if ($type === 'openai-compatible') {
            return $base !== '' && $key !== '';
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function findRoutingEligibleOrdered(): array {
        $all = $this->findAll();
        $out = [];
        foreach ($all as $p) {
            if (!$this->rowIsRoutingEligible($p)) {
                continue;
            }
            $prio = (int)($p['priority'] ?? 100);
            if ($prio < 0 || $prio > 1000000) {
                continue;
            }
            $out[] = $p;
        }
        usort($out, function ($a, $b) {
            $pa = (int)($a['priority'] ?? 100);
            $pb = (int)($b['priority'] ?? 100);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        return $out;
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data): array {
        $this->assertNoDuplicateLocalBaseUrl($data);

        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO providers
                (id, name, type, base_url, api_key, default_model, enabled, priority, is_local, created_at, updated_at)
            VALUES
                (:id, :name, :type, :base_url, :api_key, :default_model, :enabled, :priority, :is_local, :created_at, :updated_at)
        ');
        $stmt->execute([
            ':id'            => $data['id'],
            ':name'          => $data['name'],
            ':type'          => $data['type'],
            ':base_url'      => $data['base_url'] ?? '',
            ':api_key'       => $data['api_key'] ?? '',
            ':default_model' => $data['default_model'] ?? '',
            ':enabled'       => $data['enabled'] ?? 1,
            ':priority'      => $data['priority'] ?? 100,
            ':is_local'      => $data['is_local'] ?? 0,
            ':created_at'    => $data['created_at'] ?? date('c'),
            ':updated_at'    => $data['updated_at'] ?? date('c'),
        ]);
        return $this->findById($data['id']);
    }

    public function delete(string $id): void {
        $stmt = $this->pdo->prepare('DELETE FROM providers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function setEnabled(string $id, bool $enabled): ?array {
        $stmt = $this->pdo->prepare('UPDATE providers SET enabled = :enabled, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':enabled' => $enabled ? 1 : 0,
            ':updated_at' => date('c'),
        ]);
        return $this->findById($id);
    }

    private function assertNoDuplicateLocalBaseUrl(array $data): void {
        $type = strtolower((string)($data['type'] ?? ''));
        if (!in_array($type, ['ollama', 'lmstudio'], true)) {
            return;
        }

        $baseUrl = trim((string)($data['base_url'] ?? ''));
        if ($baseUrl === '') {
            return;
        }

        $normalized = $this->normalizeBaseUrl($baseUrl);
        if ($normalized === '') {
            return;
        }

        $id = (string)($data['id'] ?? '');

        $stmt = $this->pdo->prepare('SELECT id, base_url FROM providers WHERE type = ? AND id != ?');
        $stmt->execute([$type, $id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $otherBaseUrl = trim((string)($row['base_url'] ?? ''));
            if ($otherBaseUrl === '') continue;
            if ($this->normalizeBaseUrl($otherBaseUrl) === $normalized) {
                throw new \RuntimeException('A local provider of this type already uses this address.');
            }
        }
    }

    private function normalizeBaseUrl(string $baseUrl): string {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') return '';

        $parts = @parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $baseUrl;
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host   = strtolower((string)$parts['host']);
        $port   = isset($parts['port']) ? (int)$parts['port'] : null;
        $path   = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';

        $out = $scheme . '://' . $host;
        if ($port) $out .= ':' . $port;
        if ($path !== '') $out .= $path;
        return $out;
    }
}
