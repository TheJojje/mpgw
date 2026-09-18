<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class TemplateRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForApplication(int $applicationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM templates WHERE application_id = ? AND is_active = 1 ORDER BY priority ASC, id ASC'
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function forApplication(int $applicationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM templates WHERE application_id = ? ORDER BY priority ASC, id ASC');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(
        int $applicationId,
        string $name,
        ?string $fromPattern,
        string $fromMatchType,
        ?string $subjectRegex,
        int $priority
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO templates (application_id, name, from_pattern, from_match_type, subject_regex, priority)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$applicationId, $name, $fromPattern, $fromMatchType, $subjectRegex, $priority]);
        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        ?string $fromPattern,
        string $fromMatchType,
        ?string $subjectRegex,
        int $priority
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE templates
             SET name = ?, from_pattern = ?, from_match_type = ?, subject_regex = ?, priority = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $fromPattern, $fromMatchType, $subjectRegex, $priority, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete: mallen behalls (extraction_rules/extracted_records
     * refererar den), men plockas inte upp av activeForApplication(), som
     * anvands av produktionsmatchningen.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE templates SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE templates SET is_active = 1 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
