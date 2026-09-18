<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class MailboxRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function allActiveWithApplication(): array
    {
        $stmt = $this->db->query(
            'SELECT m.*, a.id AS application_id_check, a.name AS application_name
             FROM mailboxes m
             INNER JOIN applications a ON a.id = m.application_id
             WHERE m.is_active = 1 AND a.is_active = 1'
        );
        return $stmt->fetchAll();
    }

    public function create(
        int $applicationId,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $secretCiphertext,
        string $secretNonce,
        string $folder = 'INBOX'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO mailboxes (application_id, host, port, encryption, username, secret_ciphertext, secret_nonce, folder)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$applicationId, $host, $port, $encryption, $username, $secretCiphertext, $secretNonce, $folder]);
        return (int) $this->db->lastInsertId();
    }

    /** Hamtar en mailbox oavsett aktiv-status (behovs for redigering/radering). */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mailboxes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Uppdaterar anslutningsuppgifter. Losenordet lamnas orort om ingen ny secret ges (se controller). */
    public function update(
        int $id,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $folder
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE mailboxes SET host = ?, port = ?, encryption = ?, username = ?, folder = ? WHERE id = ?'
        );
        $stmt->execute([$host, $port, $encryption, $username, $folder, $id]);
        return $stmt->rowCount() > 0;
    }

    public function updateSecret(int $id, string $secretCiphertext, string $secretNonce): void
    {
        $stmt = $this->db->prepare('UPDATE mailboxes SET secret_ciphertext = ?, secret_nonce = ? WHERE id = ?');
        $stmt->execute([$secretCiphertext, $secretNonce, $id]);
    }

    public function updateLastPolled(int $mailboxId, ?string $lastSeenUid): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mailboxes SET last_polled_at = NOW(), last_seen_uid = COALESCE(?, last_seen_uid) WHERE id = ?'
        );
        $stmt->execute([$lastSeenUid, $mailboxId]);
    }

    /**
     * Soft-delete: mailboxen behalls (raw_emails/ingest_log refererar den),
     * men pollas inte langre och doljs fran den vanliga listan.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE mailboxes SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
