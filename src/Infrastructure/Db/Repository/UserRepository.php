<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function allActive(): array
    {
        $stmt = $this->db->query('SELECT * FROM users WHERE is_active = 1 ORDER BY name');
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM users ORDER BY name');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $stmt->execute([$email]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(string $name, string $email, string $passwordHash): int
    {
        $stmt = $this->db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $passwordHash]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $email): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
        return $stmt->rowCount() > 0;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Soft-delete: samma monster som ApplicationRepository::deactivate. */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countActiveByRoleSlug(string $slug): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT u.id)
             FROM users u
             JOIN users_user_roles uur ON uur.user_id = u.id
             JOIN users_roles r ON r.id = uur.role_id
             WHERE u.is_active = 1 AND r.slug = ?'
        );
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,int> */
    public function roleIdsForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT role_id FROM users_user_roles WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array<string,mixed>> roller (id, name, slug) for en anvandare */
    public function rolesForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.name, r.slug
             FROM users_roles r
             JOIN users_user_roles uur ON uur.role_id = r.id
             WHERE uur.user_id = ?
             ORDER BY r.name'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** @param array<int,int> $roleIds */
    public function setRoles(int $userId, array $roleIds): void
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM users_user_roles WHERE user_id = ?');
            $del->execute([$userId]);

            if ($roleIds !== []) {
                $insert = $this->db->prepare('INSERT INTO users_user_roles (user_id, role_id) VALUES (?, ?)');
                foreach (array_unique($roleIds) as $roleId) {
                    $insert->execute([$userId, $roleId]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
