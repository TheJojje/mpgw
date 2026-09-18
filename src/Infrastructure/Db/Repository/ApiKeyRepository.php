<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class ApiKeyRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT ak.*, a.name AS application_name FROM api_keys ak
             LEFT JOIN applications a ON a.id = ak.application_id
             ORDER BY ak.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ak.*, a.name AS application_name FROM api_keys ak
             LEFT JOIN applications a ON a.id = ak.application_id
             WHERE ak.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * application_id ingar medvetet inte har - en nyckel ar alltid knuten
     * till exakt en applikation vid skapandet och det ska inte ga att
     * andra i efterhand (se ApiKeysController::update()).
     *
     * @param array<int,string> $scopes
     */
    public function update(int $id, string $consumerName, array $scopes, ?int $rateLimitPerMinute): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE api_keys SET consumer_name = ?, scopes = ?, rate_limit_per_minute = ? WHERE id = ?'
        );
        $stmt->execute([$consumerName, json_encode($scopes), $rateLimitPerMinute, $id]);
        return $stmt->rowCount() > 0;
    }

    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET is_active = 1 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
