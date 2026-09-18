<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class IngestLogRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(?int $mailboxId, ?int $rawEmailId, string $event, ?string $message = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ingest_log (mailbox_id, raw_email_id, event, message) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$mailboxId, $rawEmailId, $event, $message]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentForMailbox(int $mailboxId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ingest_log WHERE mailbox_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $mailboxId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
