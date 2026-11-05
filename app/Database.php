<?php
declare(strict_types=1);
namespace App;
use PDO;
final class Database {
    private PDO $pdo;
    public function __construct(array $config) {
        $this->pdo = new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $this->run("SET time_zone = '+00:00'");
    }
    public function run(string $sql, array $params = []): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function all(string $sql, array $params = []): array { return $this->run($sql,$params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql,$params)->fetch() ?: null; }
    public function id(): int { return (int)$this->pdo->lastInsertId(); }
    public function transaction(callable $fn): mixed {
        $this->pdo->beginTransaction();
        try { $result=$fn(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
