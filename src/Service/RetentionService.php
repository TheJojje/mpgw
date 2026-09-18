<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use PDO;

/**
 * Raderar extraherad data (extracted_records/extracted_fields) och ratt
 * mail (raw_emails) aldre an respektive applikations retention_days /
 * raw_email_retention_days. NULL betyder "spara for alltid" for den
 * tabellen.
 *
 * extracted_records.raw_email_id har ON DELETE CASCADE mot raw_emails
 * (se database/migrations/001_create_schema.sql), sa radering av gamla
 * raw_emails tar automatiskt med sig deras extracted_records (+
 * extracted_fields via fk_fields_record CASCADE). Darfor kors raw_emails-
 * cleanup forst - den taucker fallet dar raw_email_retention_days ar
 * kortare an retention_days. Den separata extracted_records-cleanup som
 * foljer taucker det omvanda fallet (retention_days kortare an
 * raw_email_retention_days).
 *
 * Batchad DELETE (LIMIT + loop) for att undvika langa lasningar pa stora
 * tabeller. Kors fran bin/cleanup-retention.php via cron.
 */
final class RetentionService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly PDO $db,
        private readonly ApplicationRepository $applications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{applications_checked:int,records_deleted:int,raw_emails_deleted:int,errors:array<int,string>} */
    public function cleanupAll(): array
    {
        $applications = $this->applications->all();
        $totalRecordsDeleted = 0;
        $totalRawEmailsDeleted = 0;
        $errors = [];

        foreach ($applications as $application) {
            $applicationId = (int) $application['id'];
            $retentionDays = $application['retention_days'] !== null ? (int) $application['retention_days'] : null;
            $rawEmailRetentionDays = $application['raw_email_retention_days'] !== null ? (int) $application['raw_email_retention_days'] : null;

            try {
                $rawEmailsDeleted = 0;
                if ($rawEmailRetentionDays !== null) {
                    $rawEmailsDeleted = $this->cleanupRawEmails($applicationId, $rawEmailRetentionDays);
                    $totalRawEmailsDeleted += $rawEmailsDeleted;
                }

                $recordsDeleted = 0;
                if ($retentionDays !== null) {
                    $recordsDeleted = $this->cleanupExtractedRecords($applicationId, $retentionDays);
                    $totalRecordsDeleted += $recordsDeleted;
                }

                if ($rawEmailsDeleted > 0 || $recordsDeleted > 0) {
                    $this->audit->log(
                        'retention',
                        'cleanup',
                        null,
                        'application',
                        $applicationId,
                        "Rensade {$recordsDeleted} extraherade poster och {$rawEmailsDeleted} råmail (application #{$applicationId})",
                        [
                            'records_deleted' => $recordsDeleted,
                            'raw_emails_deleted' => $rawEmailsDeleted,
                            'retention_days' => $retentionDays,
                            'raw_email_retention_days' => $rawEmailRetentionDays,
                        ],
                        'info',
                        'cron',
                    );
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('Applikation #%d (%s): %s', $applicationId, $application['name'], $e->getMessage());
            }
        }

        return [
            'applications_checked' => count($applications),
            'records_deleted' => $totalRecordsDeleted,
            'raw_emails_deleted' => $totalRawEmailsDeleted,
            'errors' => $errors,
        ];
    }

    private function cleanupExtractedRecords(int $applicationId, int $retentionDays): int
    {
        $templateIds = $this->templateIdsFor($applicationId);
        if ($templateIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $deleteStmt = $this->db->prepare(
            "DELETE FROM extracted_records
             WHERE template_id IN ({$placeholders})
               AND processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT ?"
        );

        $deletedTotal = 0;
        do {
            $deleteStmt->execute([...$templateIds, $retentionDays, self::BATCH_SIZE]);
            $deletedThisBatch = $deleteStmt->rowCount();
            $deletedTotal += $deletedThisBatch;
        } while ($deletedThisBatch === self::BATCH_SIZE);

        return $deletedTotal;
    }

    private function cleanupRawEmails(int $applicationId, int $retentionDays): int
    {
        $mailboxIds = $this->mailboxIdsFor($applicationId);
        if ($mailboxIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($mailboxIds), '?'));
        $deleteStmt = $this->db->prepare(
            "DELETE FROM raw_emails
             WHERE mailbox_id IN ({$placeholders})
               AND fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT ?"
        );

        $deletedTotal = 0;
        do {
            $deleteStmt->execute([...$mailboxIds, $retentionDays, self::BATCH_SIZE]);
            $deletedThisBatch = $deleteStmt->rowCount();
            $deletedTotal += $deletedThisBatch;
        } while ($deletedThisBatch === self::BATCH_SIZE);

        return $deletedTotal;
    }

    /** @return list<int> */
    private function templateIdsFor(int $applicationId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM templates WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function mailboxIdsFor(int $applicationId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM mailboxes WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
