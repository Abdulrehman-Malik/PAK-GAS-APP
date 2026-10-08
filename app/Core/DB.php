<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class DB
{
    private int $transactionDepth = 0;

    private function __construct(private readonly PDO $pdo) {}

    public static function fromConfig(array $config): self
    {
        $db = $config['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

        try {
            return new self(new PDO($dsn, $db['user'], $db['pass'], $options));
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1049) throw $exception;
            if (!preg_match('/^[A-Za-z0-9_$-]+$/', (string) $db['name'])) throw new \RuntimeException('Invalid DB_NAME configuration.');
            $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], $options);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return new self(new PDO($dsn, $db['user'], $db['pass'], $options));
        }
    }

    public function pdo(): PDO { return $this->pdo; }
    public function lastInsertId(): int { return (int) $this->pdo->lastInsertId(); }
    public function execute(string $sql, array $params = []): PDOStatement { $statement = $this->pdo->prepare($sql); $statement->execute($params); return $statement; }
    public function fetchOne(string $sql, array $params = []): ?array { $row = $this->execute($sql, $params)->fetch(); return $row === false ? null : $row; }
    public function fetchAll(string $sql, array $params = []): array { return $this->execute($sql, $params)->fetchAll(); }
    public function transaction(callable $callback): mixed
    {
        $nested = $this->pdo->inTransaction();
        if (!$nested) {
            $this->pdo->beginTransaction();
        } else {
            $this->transactionDepth++;
            $savepoint = 'sp_' . $this->transactionDepth;
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback($this);
            if ($nested) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $this->transactionDepth--;
            } else {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($nested) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $this->transactionDepth--;
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
