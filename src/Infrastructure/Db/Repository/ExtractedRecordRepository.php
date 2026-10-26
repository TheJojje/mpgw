<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use Mpgw\Service\RuleEngineResult;
use PDO;

final class ExtractedRecordRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Sparar resultatet av en RuleEngine-korning: en extracted_records-rad
     * plus en extracted_fields-rad per falt (EAV).
     */
    public function storeResult(int $rawEmailId, ?int $templateId, RuleEngineResult $result): int
    {
        $this->db->beginTransaction();
        try {
            $recordId = $this->insertResult($rawEmailId, $templateId, $result);
            $this->db->commit();
            return $recordId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Ersatter mailets befintliga record(s) (och deras fält via ON DELETE
     * CASCADE) med ett nytt resultat, atomart. Anvands vid "Parsa om".
     */
    public function replaceResult(int $rawEmailId, ?int $templateId, RuleEngineResult $result): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM extracted_records WHERE raw_email_id = ?')->execute([$rawEmailId]);
            $recordId = $this->insertResult($rawEmailId, $templateId, $result);
            $this->db->commit();
            return $recordId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertResult(int $rawEmailId, ?int $templateId, RuleEngineResult $result): int
    {
        $uuid = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO extracted_records (uuid, raw_email_id, template_id, status, match_rate)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$uuid, $rawEmailId, $templateId, $result->status, $result->matchRate]);
        $recordId = (int) $this->db->lastInsertId();

        $fieldStmt = $this->db->prepare(
            'INSERT INTO extracted_fields (extracted_record_id, field_name, field_value, matched, rule_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($result->fields as $field) {
            $fieldStmt->execute([
                $recordId,
                $field['field_name'],
                $field['value'],
                $field['matched'] ? 1 : 0,
                $field['rule_id'],
            ]);
        }

        return $recordId;
    }

    /** @return array<int,array<string,mixed>> recent records med matchrate, for larmberakning */
    public function recentForTemplate(int $templateId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM extracted_records WHERE template_id = ? ORDER BY processed_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $templateId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM extracted_records WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function fieldsForRecord(int $recordId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM extracted_fields WHERE extracted_record_id = ?');
        $stmt->execute([$recordId]);
        return $stmt->fetchAll();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
