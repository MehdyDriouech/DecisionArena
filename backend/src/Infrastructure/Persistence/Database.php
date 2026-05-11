<?php
namespace Infrastructure\Persistence;

use Domain\CognitiveGovernance\RuntimeFilesystemGuard;

class Database {
    private static ?Database $instance = null;
    private \PDO $pdo;

    private function __construct() {
        $dbPath = __DIR__ . '/../../../storage/database/app.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            RuntimeFilesystemGuard::inspect('mkdir', $dir, ['source' => 'Database::__construct']);
            mkdir($dir, 0755, true);
        }
        $this->pdo = new RuntimeAwarePdo('sqlite:' . $dbPath);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /** @return \PDO PDO partagé (alias pratique pour le code qui attend une connexion directe). */
    public static function getConnection(): \PDO {
        return self::getInstance()->pdo();
    }

    public function pdo(): \PDO {
        return $this->pdo;
    }
}
