<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class DatabaseService
{
    public function __construct(private readonly Connection $connection) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        return $row ?: null;
    }

    public function insert(string $table, array $data): int
    {
        $this->connection->insert($table, $data);
        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, array $data, array $criteria): void
    {
        $this->connection->update($table, $data, $criteria);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->executeStatement($sql, $params);
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional($callback);
    }
}
