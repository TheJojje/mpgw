<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\DeletedEmailRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractedRecordRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractionRuleRepository;
use Mpgw\Infrastructure\Db\Repository\IngestLogRepository;
use Mpgw\Infrastructure\Db\Repository\MailboxRepository;
use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Infrastructure\Imap\WebklexImapClient;
use Throwable;

/**
 * Orkestrerar en hel polling-omgang: for varje aktiv mailbox, hamta nya
 * mail via IMAP, spara ratt mail (ALLTID), matcha mot ratt mall, kor
 * extraction_rules och spara resultatet. Kors fran bin/poll-mailboxes.php
 * via cron.
 */
final class MailboxPoller
{
    // Skydd mot resursuttomning fran onormalt stora mailkroppar: en
    // illvillig/felaktig avsandare med atkomst till en overvakad brevlada
    // ska inte kunna belasta CPU/minne via upprepade preg_replace-pass i
    // HtmlToTextNormalizer eller svalla LONGTEXT-lagringen obegransat.
    private const MAX_BODY_BYTES = 5_000_000; // 5 MB

    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly RawEmailRepository $rawEmails,
        private readonly TemplateRepository $templates,
        private readonly ExtractionRuleRepository $rules,
        private readonly ExtractedRecordRepository $records,
        private readonly IngestLogRepository $ingestLog,
        private readonly WebklexImapClient $imapClient,
        private readonly CredentialCipher $cipher,
        private readonly HtmlToTextNormalizer $normalizer,
        private readonly TemplateMatcher $templateMatcher,
        private readonly RuleEngine $ruleEngine,
        private readonly AuditLogger $audit,
        private readonly DeletedEmailRepository $deletedEmails,
    ) {
    }

    /** @return array{mailboxes_polled:int,emails_fetched:int,errors:array<int,string>} */
    public function pollAll(): array
    {
        $mailboxes = $this->mailboxes->allActiveWithApplication();
        $fetched = 0;
        $errors = [];

        foreach ($mailboxes as $mailbox) {
            try {
                $fetched += $this->pollOne($mailbox);
            } catch (Throwable $e) {
                $errors[] = sprintf('Mailbox #%d (%s): %s', $mailbox['id'], $mailbox['host'], $e->getMessage());
                $this->ingestLog->log((int) $mailbox['id'], null, 'imap_error', $e->getMessage());
            }
        }

        return [
            'mailboxes_polled' => count($mailboxes),
            'emails_fetched' => $fetched,
            'errors' => $errors,
        ];
    }

    private function pollOne(array $mailbox): int
    {
        $password = $this->cipher->decrypt($mailbox['secret_ciphertext'], $mailbox['secret_nonce']);

        $messages = $this->imapClient->fetchNew(
            $mailbox['host'],
            (int) $mailbox['port'],
            $mailbox['encryption'],
            $mailbox['username'],
            $password,
            $mailbox['folder'],
            $mailbox['last_seen_uid'],
        );

        $lastUid = $mailbox['last_seen_uid'];
        foreach ($messages as $message) {
            $this->ingestOne((int) $mailbox['id'], (int) $mailbox['application_id'], $message);
            $lastUid = $message['uid'];
        }

        $this->mailboxes->updateLastPolled((int) $mailbox['id'], $lastUid);

        // Cron-driven handelse (ingen inloggad anvandare/IP) - source=cron
        // sa den syns korrekt filtrerad bland ovriga log_events-handelser.
        $this->audit->log(
            'sync',
            'mailbox_synced',
            null,
            'mailbox',
            (int) $mailbox['id'],
            "Mailbox {$mailbox['username']} synkad: " . count($messages) . ' mail hämtade',
            ['mailbox_id' => $mailbox['id'], 'application_id' => $mailbox['application_id'], 'emails_fetched' => count($messages)],
            source: 'cron',
        );

        return count($messages);
    }

    private function ingestOne(int $mailboxId, int $applicationId, array $message): void
    {
        $isHtml = ($message['html_body'] ?? null) !== null && $message['html_body'] !== '';
        $body = $isHtml ? $message['html_body'] : ($message['text_body'] ?? '');
        $body ??= '';

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->ingestLog->log(
                $mailboxId,
                null,
                'body_truncated',
                sprintf('Mailkropp (%d bytes) overskred graensen pa %d bytes och trunkerades fore bearbetning.', strlen($body), self::MAX_BODY_BYTES)
            );
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $normalizedText = $this->normalizer->normalize($body, $isHtml);
        $receivedAt = $message['date'] !== null ? date('Y-m-d H:i:s', strtotime($message['date'])) : null;

        // Hash pa brodtext + mottagningstid: tva mail med identisk text men
        // mottagna vid olika tidpunkter ar tva olika handelser (t.ex.
        // upprepade testbokningar av samma mall) och ska INTE dedupas bort.
        // Samma brodtext + samma mottagningstid rakas fortfarande som samma
        // mail (t.ex. om IMAP-pollingen rakar hamta om samma mail igen).
        $contentHash = hash('sha256', $normalizedText . '|' . $receivedAt);

        if ($this->rawEmails->existsByContentHash($contentHash)) {
            $this->ingestLog->log($mailboxId, null, 'fetched', 'Duplicerat innehall (content_hash), hoppar over.');
            return;
        }

        // Mail som en administratör raderat ska inte importeras om, även
        // om last_seen_uid nollställts eller servern ger nya UID:n.
        if ($this->deletedEmails->isDeleted($contentHash, $message['message_id'] ?? null, $mailboxId)) {
            $this->ingestLog->log($mailboxId, null, 'fetched', 'Raderat mail, hoppar over.');
            return;
        }

        $rawEmailId = $this->rawEmails->store([
            'mailbox_id' => $mailboxId,
            'message_id' => $message['message_id'],
            'imap_uid' => $message['uid'],
            'from_address' => $message['from'],
            'to_address' => $message['to'],
            'subject' => $message['subject'],
            'received_at' => $receivedAt,
            'raw_headers' => $message['headers'],
            'raw_body' => $body,
            'normalized_text' => $normalizedText,
            'content_hash' => $contentHash,
        ]);
        $this->ingestLog->log($mailboxId, $rawEmailId, 'fetched');

        $templates = $this->templates->activeForApplication($applicationId);
        $matchedTemplate = $this->templateMatcher->match($templates, $message['from'] ?? '', $message['subject'] ?? '');

        if ($matchedTemplate === null) {
            $this->ingestLog->log($mailboxId, $rawEmailId, 'template_not_matched');
            return;
        }

        $this->ingestLog->log($mailboxId, $rawEmailId, 'template_matched', $matchedTemplate['name']);

        // Amnesraden normaliseras separat (bara whitespace - subject ar
        // aldrig HTML) sa regler med source=subject har nagot att kora
        // mot, se RuleEngine::run().
        $normalizedSubject = $this->normalizer->normalize($message['subject'] ?? '', false);

        $rules = $this->rules->activeForTemplate((int) $matchedTemplate['id']);
        $result = $this->ruleEngine->run($rules, $normalizedText, $normalizedSubject);
        $this->records->storeResult($rawEmailId, (int) $matchedTemplate['id'], $result);
        $this->ingestLog->log($mailboxId, $rawEmailId, 'rule_engine_ran', $result->status);
    }
}
