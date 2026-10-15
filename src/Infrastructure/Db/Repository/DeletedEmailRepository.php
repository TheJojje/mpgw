<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

/**
 * Tombstones for raderade mail (se migration 009). MailboxPoller kollar
 * isDeleted() fore lagring sa att ett raderat mail inte importeras om.
 */
final class DeletedEmailRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isDeleted(string $contentHash, ?string $messageId, ?int $mailboxId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM deleted_emails WHERE content_hash = ? LIMIT 1');
        $stmt->execute([$contentHash]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        // Extra skydd om mailets avsandartid/kropp skulle normaliseras
        // annorlunda: samma Message-ID i samma mailbox ar samma mail.
        if ($messageId === null || $messageId === '' || $mailboxId === null) {
            return false;
        }

        $stmt = $this->db->prepare('SELECT 1 FROM deleted_emails WHERE mailbox_id = ? AND message_id = ? LIMIT 1');
        $stmt->execute([$mailboxId, $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sparar tombstone och hard-raderar mailet i samma transaktion.
     * extracted_records/extracted_fields foljer med via ON DELETE CASCADE.
     */
    public function deleteEmailWithTombstone(array $email, ?int $deletedBy): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT IGNORE INTO deleted_emails (mailbox_id, content_hash, message_id, imap_uid, deleted_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $email['mailbox_id'],
                $email['content_hash'],
                $email['message_id'],
                $email['imap_uid'],
                $deletedBy,
            ]);

            $this->db->prepare('DELETE FROM raw_emails WHERE id = ?')->execute([$email['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
