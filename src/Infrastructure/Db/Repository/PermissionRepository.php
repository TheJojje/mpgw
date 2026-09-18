<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class PermissionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM users_permissions WHERE is_active = 1 ORDER BY module, slug');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users_permissions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Rattigheter grupperade per modul, for kryssrute-matrisen i
     * roller/new.twig och roller/edit.twig.
     *
     * @return array<string,array<int,array<string,mixed>>> modul => rattigheter
     */
    public function allGroupedByModule(): array
    {
        $grouped = [];
        foreach ($this->all() as $permission) {
            $grouped[$permission['module']][] = $permission;
        }
        return $grouped;
    }
}
