<?php

declare(strict_types=1);

namespace Mpgw\Infrastructure\Db\Repository;

use PDO;

final class ApplicationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function allActive(): array
    {
        $stmt = $this->db->query('SELECT * FROM applications WHERE is_active = 1 ORDER BY name');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM applications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM applications ORDER BY name');
        return $stmt->fetchAll();
    }

    public function create(
        string $name,
        ?string $description,
        ?string $alertEmail,
        ?string $alertWebhookUrl,
        ?int $retentionDays = 90,
        ?int $rawEmailRetentionDays = 90,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO applications (name, description, alert_email, alert_webhook_url, retention_days, raw_email_retention_days)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $alertEmail, $alertWebhookUrl, $retentionDays, $rawEmailRetentionDays]);
        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        ?string $description,
        ?string $alertEmail,
        ?string $alertWebhookUrl,
        ?int $retentionDays,
        ?int $rawEmailRetentionDays,
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET name = ?, description = ?, alert_email = ?, alert_webhook_url = ?, retention_days = ?, raw_email_retention_days = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $description, $alertEmail, $alertWebhookUrl, $retentionDays, $rawEmailRetentionDays, $id]);
        return $stmt->rowCount() > 0;
    }

    public function updateAlertFlags(int $id, bool $onPartial, bool $onThreadAnomaly): void
    {
        $this->db->prepare('UPDATE applications SET alert_on_partial = ?, alert_on_thread_anomaly = ? WHERE id = ?')
            ->execute([$onPartial ? 1 : 0, $onThreadAnomaly ? 1 : 0, $id]);
    }

    /** @param array{thread_key_fields:?string,thread_event_field:?string,thread_open_values:?string,thread_close_values:?string} $config */
    public function updateThreading(int $id, array $config): void
    {
        $this->db->prepare(
            'UPDATE applications
             SET thread_key_fields = ?, thread_event_field = ?, thread_open_values = ?, thread_close_values = ?
             WHERE id = ?'
        )->execute([
            $config['thread_key_fields'],
            $config['thread_event_field'],
            $config['thread_open_values'],
            $config['thread_close_values'],
            $id,
        ]);
    }

    /**
     * Soft-delete: applikationen behalls (mailboxes/mallar/raw_emails
     * refererar den), men doljs fran pollingen - se
     * MailboxRepository::allActiveWithApplication, som redan kraver
     * a.is_active = 1.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE applications SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE applications SET is_active = 1 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
