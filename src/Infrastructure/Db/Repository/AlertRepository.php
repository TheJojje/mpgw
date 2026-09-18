<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class AlertRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM alerts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Sätter status till 'acknowledged'. Bara giltigt från 'open'. */
    public function acknowledge(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE alerts SET status = 'acknowledged' WHERE id = ? AND status = 'open'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Sätter status till 'resolved' och stämplar resolved_at. Giltigt från 'open' eller 'acknowledged'. */
    public function resolve(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE alerts SET status = 'resolved', resolved_at = NOW() WHERE id = ? AND status IN ('open', 'acknowledged')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
