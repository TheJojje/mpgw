<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use Mpgw\Service\CredentialCipher;
use PDO;

/**
 * raw_headers/raw_body innehaller det oredigerade mailinnehallet (kan
 * baera PII) och krypteras darfor at rest med samma
 * sodium_crypto_secretbox-monster som mailboxes.secret_ciphertext (se
 * CredentialCipher). Krypteringen/dekrypteringen sker HAR, transparent
 * for alla anropare - se find()/findByUuid()/recent()/findRawForApi()
 * som alla dekrypterar innan raden returneras. normalized_text lagras i
 * klartext (se migration 008_encrypt_raw_email.sql for motivering).
 */
final class RawEmailRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly CredentialCipher $cipher,
    ) {
    }

    public function existsByContentHash(string $contentHash): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM raw_emails WHERE content_hash = ? LIMIT 1');
        $stmt->execute([$contentHash]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sparar ett rått mail - anropas ALLTID vid ingest, oavsett om det
     * sedan gar att matcha mot nagon mall eller inte.
     */
    public function store(array $data): int
    {
        $encrypted = $this->cipher->encryptMany([
            'raw_headers' => $data['raw_headers'] ?? '',
            'raw_body' => $data['raw_body'] ?? '',
        ]);

        $stmt = $this->db->prepare(
            'INSERT INTO raw_emails
                (uuid, mailbox_id, message_id, imap_uid, from_address, to_address, subject,
                 received_at, raw_headers, raw_body, raw_nonce, normalized_text, content_hash)
             VALUES (:uuid, :mailbox_id, :message_id, :imap_uid, :from_address, :to_address, :subject,
                     :received_at, :raw_headers, :raw_body, :raw_nonce, :normalized_text, :content_hash)'
        );
        $uuid = $this->generateUuid();
        $stmt->bindValue('uuid', $uuid);
        $stmt->bindValue('mailbox_id', $data['mailbox_id']);
        $stmt->bindValue('message_id', $data['message_id']);
        $stmt->bindValue('imap_uid', $data['imap_uid']);
        $stmt->bindValue('from_address', $data['from_address']);
        $stmt->bindValue('to_address', $data['to_address']);
        $stmt->bindValue('subject', $data['subject']);
        $stmt->bindValue('received_at', $data['received_at']);
        $stmt->bindValue('raw_headers', $encrypted['ciphertexts']['raw_headers'], PDO::PARAM_LOB);
        $stmt->bindValue('raw_body', $encrypted['ciphertexts']['raw_body'], PDO::PARAM_LOB);
        $stmt->bindValue('raw_nonce', $encrypted['nonce'], PDO::PARAM_LOB);
        $stmt->bindValue('normalized_text', $data['normalized_text']);
        $stmt->bindValue('content_hash', $data['content_hash']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM raw_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM raw_emails WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50, ?int $mailboxId = null): array
    {
        if ($mailboxId !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM raw_emails WHERE mailbox_id = ? ORDER BY fetched_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $mailboxId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare('SELECT * FROM raw_emails ORDER BY fetched_at DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        return array_map(fn (array $row) => $this->decryptRow($row), $stmt->fetchAll());
    }

    /**
     * Ravaran (from/subject/received_at/raw_headers/raw_body) for ett
     * extracted_record, atkomstbegransat till en given application_id -
     * anvands av det externa API:ts /records/{uuid}/raw-endpoint
     * (RecordsController::raw()) sa dekrypteringen sker har istallet for
     * att controllern behover kanna till nyckel-/nonce-hanteringen.
     */
    public function findRawForRecord(string $recordUuid, int $applicationId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT re.* FROM extracted_records er
             INNER JOIN raw_emails re ON re.id = er.raw_email_id
             LEFT JOIN templates t ON t.id = er.template_id
             WHERE er.uuid = ? AND t.application_id = ?'
        );
        $stmt->execute([$recordUuid, $applicationId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->decryptRow($row);
    }

    /** @return array<string,mixed>|null */
    private function decryptRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        if ($row['raw_headers'] !== null && $row['raw_nonce'] !== null) {
            $row['raw_headers'] = $this->cipher->decrypt($row['raw_headers'], $row['raw_nonce']);
        }
        if ($row['raw_body'] !== null && $row['raw_nonce'] !== null) {
            $row['raw_body'] = $this->cipher->decrypt($row['raw_body'], $row['raw_nonce']);
        }

        return $row;
    }

    private function generateUuid(): string
    {
        // Undviker ett hart beroende pa ramsey/uuid for MVP - anvander
        // PHPs inbyggda random_bytes for att bygga en UUIDv4.
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
