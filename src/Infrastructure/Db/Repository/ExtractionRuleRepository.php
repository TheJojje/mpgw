<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class ExtractionRuleRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForTemplate(int $templateId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM extraction_rules WHERE template_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$templateId]);
        return $stmt->fetchAll();
    }

    public function create(
        int $templateId,
        string $fieldName,
        ?string $fieldLabel,
        string $dataType,
        string $pattern,
        bool $isRequired,
        ?string $sampleSourceSnippet,
        ?string $createdBy,
        string $source = 'body'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO extraction_rules
                (template_id, field_name, field_label, data_type, pattern, is_required, sample_source_snippet, created_by, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $templateId,
            $fieldName,
            $fieldLabel,
            $dataType,
            $pattern,
            $isRequired ? 1 : 0,
            $sampleSourceSnippet,
            $createdBy,
            $source,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Soft-delete: satter is_active=0 istallet for att radera raden, sa
     * historik (extracted_fields.rule_id) och sample_source_snippet
     * bevaras om man vill aterstalla regeln senare.
     */
    public function deactivate(int $ruleId, int $templateId): bool
    {
        $stmt = $this->db->prepare('UPDATE extraction_rules SET is_active = 0 WHERE id = ? AND template_id = ?');
        $stmt->execute([$ruleId, $templateId]);
        return $stmt->rowCount() > 0;
    }

    public function find(int $ruleId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM extraction_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * sample_source_snippet lamnas medvetet oforandrad vid redigering - den
     * bevarar den ursprungliga textmarkeringen som regeln en gang skapades
     * fran, aven om monstret/faltnamnet andras senare.
     */
    public function update(
        int $ruleId,
        int $templateId,
        string $fieldName,
        ?string $fieldLabel,
        string $dataType,
        string $pattern,
        bool $isRequired,
        string $source
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE extraction_rules
             SET field_name = ?, field_label = ?, data_type = ?, pattern = ?, is_required = ?, source = ?
             WHERE id = ? AND template_id = ?'
        );
        $stmt->execute([
            $fieldName,
            $fieldLabel,
            $dataType,
            $pattern,
            $isRequired ? 1 : 0,
            $source,
            $ruleId,
            $templateId,
        ]);
        return $stmt->rowCount() > 0;
    }
}
