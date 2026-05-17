<?php
namespace Infrastructure\Persistence;

class DemoUserRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findByLogin(string $login): ?array {
        $login = strtolower(trim($login));
        if ($login === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM demo_users WHERE login = ? AND enabled = 1 LIMIT 1'
        );
        $stmt->execute([$login]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM demo_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{id:string,login:string,password_hash:string,role:string,enabled?:int,created_at?:string} $data
     */
    public function upsert(array $data, bool $forcePassword = false): array {
        $login = strtolower(trim((string)$data['login']));
        $existing = $this->findByLogin($login);
        if ($existing && !$forcePassword && trim((string)($existing['password_hash'] ?? '')) !== '') {
            return $existing;
        }
        $now = date('c');
        $id = (string)($data['id'] ?? $login);
        $stmt = $this->pdo->prepare('
            INSERT INTO demo_users (id, login, password_hash, role, enabled, created_at, updated_at)
            VALUES (:id, :login, :password_hash, :role, :enabled, :created_at, :updated_at)
            ON CONFLICT(login) DO UPDATE SET
                password_hash = excluded.password_hash,
                role = excluded.role,
                enabled = excluded.enabled,
                updated_at = excluded.updated_at
        ');
        $stmt->execute([
            ':id' => $id,
            ':login' => $login,
            ':password_hash' => $data['password_hash'],
            ':role' => $data['role'],
            ':enabled' => (int)($data['enabled'] ?? 1),
            ':created_at' => $existing['created_at'] ?? $now,
            ':updated_at' => $now,
        ]);
        return $this->findByLogin($login) ?? $data;
    }

    /** @return array{login:string,role:string}|null */
    public function verifyCredentials(string $login, string $password): ?array {
        $row = $this->findByLogin($login);
        if (!$row) {
            return null;
        }
        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }
        return [
            'id' => (string)$row['id'],
            'login' => (string)$row['login'],
            'role' => (string)$row['role'],
        ];
    }

    /** @return array{login:string,role:string} */
    public function toPublicUser(array $row): array {
        return [
            'login' => (string)($row['login'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
        ];
    }
}
