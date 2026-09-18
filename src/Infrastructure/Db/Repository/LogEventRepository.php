<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class LogEventRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $contentParams */
    public function insert(
        string $module,
        string $action,
        string $level,
        string $source,
        ?int $actorUserId,
        ?string $objectType,
        ?int $objectId,
        ?string $content,
        array $contentParams = [],
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO log_events (module, action, level, source, actor_user_id, object_type, object_id, content, content_params)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $module,
            $action,
            $level,
            $source,
            $actorUserId,
            $objectType,
            $objectId,
            $content,
            $contentParams === [] ? null : json_encode($contentParams, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT le.*, u.name AS actor_name
             FROM log_events le
             LEFT JOIN users u ON u.id = le.actor_user_id
             WHERE le.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{module?:?string,actor_user_id?:?int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare(
            "SELECT le.*, u.name AS actor_name
             FROM log_events le
             LEFT JOIN users u ON u.id = le.actor_user_id
             {$where}
             ORDER BY le.id DESC
             LIMIT :mpgw_limit OFFSET :mpgw_offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('mpgw_limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('mpgw_offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @param array{module?:?string,actor_user_id?:?int} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM log_events le {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{clause:string}>|array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['module'])) {
            $clauses[] = 'le.module = :module';
            $params['module'] = $filters['module'];
        }
        if (!empty($filters['actor_user_id'])) {
            $clauses[] = 'le.actor_user_id = :actor_user_id';
            $params['actor_user_id'] = (int) $filters['actor_user_id'];
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /**
     * Foregaende log_events-rad for samma objekt, anvands av AuditDiffer
     * for att rakna ut en falt-diff mot narmast foregaende handelse.
     */
    public function previousForObject(string $objectType, int $objectId, string $beforeCreatedAt, int $beforeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM log_events
             WHERE object_type = ? AND object_id = ?
               AND (created_at < ? OR (created_at = ? AND id < ?))
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$objectType, $objectId, $beforeCreatedAt, $beforeCreatedAt, $beforeId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentForObject(string $objectType, int $objectId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT le.*, u.name AS actor_name
             FROM log_events le
             LEFT JOIN users u ON u.id = le.actor_user_id
             WHERE le.object_type = ? AND le.object_id = ?
             ORDER BY le.created_at DESC, le.id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $objectType);
        $stmt->bindValue(2, $objectId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int,string> distinkta moduler, for filter-dropdown i logs/index.twig */
    public function distinctModules(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT module FROM log_events ORDER BY module');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
