<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class RoleRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function allActive(): array
    {
        $stmt = $this->db->query('SELECT * FROM users_roles WHERE is_active = 1 ORDER BY name');
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> roller med antal kopplade rattigheter */
    public function allWithPermissionCount(): array
    {
        $stmt = $this->db->query(
            'SELECT r.*,
                (SELECT COUNT(*) FROM users_role_permissions urp WHERE urp.role_id = r.id) AS permission_count
             FROM users_roles r
             ORDER BY r.is_system DESC, r.name'
        );
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users_roles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users_roles WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users_roles WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $name, string $slug, ?string $description): int
    {
        $stmt = $this->db->prepare('INSERT INTO users_roles (name, slug, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $description]);
        return (int) $this->db->lastInsertId();
    }

    /** Slug och is_system ar aldrig redigerbara via UI - bara namn/beskrivning. */
    public function update(int $id, string $name, ?string $description): bool
    {
        $stmt = $this->db->prepare('UPDATE users_roles SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Hard delete - endast tillaten for anpassade (icke-system) roller, se RolesController::delete. */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users_roles WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,int> */
    public function permissionIdsForRole(int $roleId): array
    {
        $stmt = $this->db->prepare('SELECT permission_id FROM users_role_permissions WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,string> */
    public function permissionSlugsForRole(int $roleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.slug FROM users_permissions p
             JOIN users_role_permissions urp ON urp.permission_id = p.id
             WHERE urp.role_id = ? AND p.is_active = 1'
        );
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<int,int> $permissionIds */
    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM users_role_permissions WHERE role_id = ?');
            $del->execute([$roleId]);

            if ($permissionIds !== []) {
                $insert = $this->db->prepare('INSERT INTO users_role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach (array_unique($permissionIds) as $permissionId) {
                    $insert->execute([$roleId, $permissionId]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
