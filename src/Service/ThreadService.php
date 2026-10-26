<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\AlertRepository;
use PDO;

/**
 * Haller ihop mail som hor till samma "arende" i en trad (t.ex. bokning ->
 * avbokning), utifran per-applikation-konfigurationen
 * applications.thread_* (se migration 011_threads.sql). Tjansten vet inget
 * om vad en handelse betyder - bara vilka falt som bildar nyckeln och vilka
 * varden som oppnar respektive stanger en trad.
 *
 * En trad ar en livscykel: en "open"-handelse startar den, en
 * "close"-handelse stanger den. En ny "open" efter en stangd trad startar en
 * NY trad for samma nyckel (t.ex. boka om efter avbokning).
 * Tradarna byggs om deterministiskt per nyckel i mottagningsordning, sa
 * resultatet blir detsamma oavsett i vilken ordning mailen importerats.
 */
final class ThreadService
{
    public const ANOMALY_DUPLICATE_OPEN = 'duplicate_open';
    public const ANOMALY_CLOSE_WITHOUT_OPEN = 'close_without_open';
    public const ANOMALY_DUPLICATE_CLOSE = 'duplicate_close';

    public function __construct(
        private readonly PDO $db,
        private readonly AlertRepository $alerts,
    ) {
    }

    /**
     * Rent tillstandsmaskinsteg. Tar handelser i kronologisk ordning och
     * returnerar tradtillhorighet per id.
     *
     * @param array<int,array{id:int,event:string}> $events 'open' eller 'close'
     * @return array<int,array{thread_no:int,sequence:int,state:string,anomaly:?string}> nycklat pa id
     */
    public static function assign(array $events): array
    {
        $result = [];
        $threadNo = 0;
        $sequence = 0;
        $state = null; // null = ingen trad an, annars 'open'|'closed'
        $finalState = [];

        foreach ($events as $event) {
            $anomaly = null;
            if ($event['event'] === 'open') {
                if ($state === 'open') {
                    $sequence++;
                    $anomaly = self::ANOMALY_DUPLICATE_OPEN;
                } else {
                    $threadNo++;
                    $sequence = 1;
                    $state = 'open';
                }
            } else { // close
                if ($state === 'open') {
                    $sequence++;
                    $state = 'closed';
                } elseif ($state === null) {
                    $threadNo++;
                    $sequence = 1;
                    $state = 'closed';
                    $anomaly = self::ANOMALY_CLOSE_WITHOUT_OPEN;
                } else {
                    $sequence++;
                    $anomaly = self::ANOMALY_DUPLICATE_CLOSE;
                }
            }

            $result[$event['id']] = ['thread_no' => $threadNo, 'sequence' => $sequence, 'state' => $state, 'anomaly' => $anomaly];
            $finalState[$threadNo] = $state;
        }

        // Tradens slutliga lage galler for alla dess handelser.
        foreach ($result as $id => $row) {
            $result[$id]['state'] = $finalState[$row['thread_no']];
        }

        return $result;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * @param array<string,mixed> $application rad fran applications
     * @param array<string,string> $fields matchade falt (field_name => varde)
     * @return array{key:string,event:string}|null null = mailet ingar inte i nagon trad
     */
    public static function resolve(array $application, array $fields): ?array
    {
        $keyFields = self::splitList($application['thread_key_fields'] ?? null);
        $eventField = trim((string) ($application['thread_event_field'] ?? ''));
        $openValues = array_map([self::class, 'normalize'], self::splitList($application['thread_open_values'] ?? null));
        $closeValues = array_map([self::class, 'normalize'], self::splitList($application['thread_close_values'] ?? null));

        if ($keyFields === [] || $eventField === '' || ($openValues === [] && $closeValues === [])) {
            return null;
        }

        $parts = [];
        foreach ($keyFields as $name) {
            $value = $fields[$name] ?? null;
            if ($value === null || trim($value) === '') {
                return null;
            }
            $parts[] = self::normalize($value);
        }

        $eventValue = self::normalize((string) ($fields[$eventField] ?? ''));
        if (in_array($eventValue, $openValues, true)) {
            $event = 'open';
        } elseif (in_array($eventValue, $closeValues, true)) {
            $event = 'close';
        } else {
            return null;
        }

        return ['key' => hash('sha256', $application['id'] . '|' . implode('|', $parts)), 'event' => $event];
    }

    /** @return array<int,string> */
    private static function splitList(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== ''));
    }

    /** Offentligt trad-id: stabilt for en given nyckel och livscykel. */
    public static function publicId(string $threadKey, int $threadNo): string
    {
        return substr($threadKey, 0, 12) . '-' . $threadNo;
    }

    /** Beraknar trad for ett record och bygger om berorda tradar. */
    public function syncRecord(int $recordId): void
    {
        $changed = $this->applyKey($recordId);
        foreach (array_unique(array_filter($changed)) as $key) {
            $this->rebuildKey($key);
        }
    }

    /** Beraknar om alla records i en applikation. @return int antal records */
    public function syncApplication(int $applicationId): int
    {
        $stmt = $this->db->prepare(
            'SELECT er.id FROM extracted_records er JOIN templates t ON t.id = er.template_id WHERE t.application_id = ?'
        );
        $stmt->execute([$applicationId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $keys = [];
        foreach ($ids as $id) {
            foreach ($this->applyKey((int) $id) as $key) {
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }
        foreach (array_keys($keys) as $key) {
            $this->rebuildKey($key);
        }

        return count($ids);
    }

    /** Nyckeln ett mail har idag - anvands fore radering sa tradden kan byggas om efterat. */
    public function keyForRawEmail(int $rawEmailId): ?string
    {
        $stmt = $this->db->prepare('SELECT thread_key FROM extracted_records WHERE raw_email_id = ? AND thread_key IS NOT NULL LIMIT 1');
        $stmt->execute([$rawEmailId]);
        $key = $stmt->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    /**
     * Sparar nyckel + handelse pa ett record.
     *
     * @return array<int,?string> [gammal nyckel, ny nyckel]
     */
    private function applyKey(int $recordId): array
    {
        $stmt = $this->db->prepare(
            'SELECT er.thread_key, a.*
             FROM extracted_records er
             JOIN templates t ON t.id = er.template_id
             JOIN applications a ON a.id = t.application_id
             WHERE er.id = ?'
        );
        $stmt->execute([$recordId]);
        $application = $stmt->fetch();
        if ($application === false) {
            return [];
        }
        $oldKey = $application['thread_key'] !== null ? (string) $application['thread_key'] : null;

        $fieldStmt = $this->db->prepare('SELECT field_name, field_value FROM extracted_fields WHERE extracted_record_id = ? AND matched = 1');
        $fieldStmt->execute([$recordId]);
        $fields = $fieldStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $resolved = self::resolve($application, $fields);

        if ($resolved === null) {
            $this->db->prepare(
                'UPDATE extracted_records
                 SET thread_key = NULL, thread_event = NULL, thread_no = NULL, thread_sequence = NULL, thread_state = NULL, thread_anomaly = NULL
                 WHERE id = ?'
            )->execute([$recordId]);
        } else {
            // thread_anomaly lamnas orord: rebuildKey() jamfor mot det gamla vardet
            // for att bara larma vid NYA avvikelser.
            $this->db->prepare('UPDATE extracted_records SET thread_key = ?, thread_event = ? WHERE id = ?')
                ->execute([$resolved['key'], $resolved['event'], $recordId]);
        }

        return [$oldKey, $resolved['key'] ?? null];
    }

    /** Bygger om alla tradar for en nyckel i mottagningsordning och larmar vid nya avvikelser. */
    public function rebuildKey(string $key): void
    {
        $stmt = $this->db->prepare(
            'SELECT er.id, er.thread_event, er.thread_anomaly, er.template_id, er.raw_email_id, t.application_id
             FROM extracted_records er
             JOIN raw_emails re ON re.id = er.raw_email_id
             LEFT JOIN templates t ON t.id = er.template_id
             WHERE er.thread_key = ?
             ORDER BY COALESCE(re.received_at, re.fetched_at), er.id'
        );
        $stmt->execute([$key]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return;
        }

        $assigned = self::assign(array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'event' => (string) $r['thread_event']],
            $rows
        ));

        $update = $this->db->prepare(
            'UPDATE extracted_records
             SET thread_no = ?, thread_sequence = ?, thread_state = ?, thread_anomaly = ?
             WHERE id = ?'
        );
        foreach ($rows as $row) {
            $a = $assigned[(int) $row['id']];
            $update->execute([$a['thread_no'], $a['sequence'], $a['state'], $a['anomaly'], $row['id']]);

            if ($a['anomaly'] === $row['thread_anomaly']) {
                continue;
            }
            if ($a['anomaly'] !== null) {
                if ($row['application_id'] !== null && !$this->anomalyAlertsEnabled((int) $row['application_id'])) {
                    continue;
                }
                $this->alerts->create(
                    'thread_anomaly',
                    $row['application_id'] !== null ? (int) $row['application_id'] : null,
                    $row['template_id'] !== null ? (int) $row['template_id'] : null,
                    (int) $row['raw_email_id'],
                    'warning',
                    ['anomaly' => $a['anomaly'], 'thread_id' => self::publicId($key, $a['thread_no'])],
                );
            } else {
                // Avvikelsen ar atgardad (t.ex. saknat mail aterstallt).
                $this->db->prepare(
                    "UPDATE alerts SET status = 'resolved', resolved_at = NOW()
                     WHERE type = 'thread_anomaly' AND raw_email_id = ? AND status IN ('open', 'acknowledged')"
                )->execute([$row['raw_email_id']]);
            }
        }
    }

    /** @var array<int,bool> */
    private array $anomalyAlertCache = [];

    private function anomalyAlertsEnabled(int $applicationId): bool
    {
        if (!isset($this->anomalyAlertCache[$applicationId])) {
            $stmt = $this->db->prepare('SELECT alert_on_thread_anomaly FROM applications WHERE id = ?');
            $stmt->execute([$applicationId]);
            $value = $stmt->fetchColumn();
            $this->anomalyAlertCache[$applicationId] = $value === false || (int) $value === 1;
        }

        return $this->anomalyAlertCache[$applicationId];
    }

    /**
     * Sammanfattning och exempel for admin-UI:t (applikationens redigeringssida).
     *
     * @return array{records:int,threads:int,closed:int,open:int,anomalies:int,examples:array<int,array{label:string,closed:bool,events:array<int,array{event:string,subject:string,sent_at:?string,anomaly:?string}>}>}
     */
    public function summaryForApplication(int $applicationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS records,
                    COUNT(DISTINCT er.thread_key, er.thread_no) AS threads,
                    COUNT(DISTINCT CASE WHEN er.thread_state = 'closed' THEN CONCAT(er.thread_key, er.thread_no) END) AS closed,
                    COUNT(DISTINCT CASE WHEN er.thread_state = 'open' THEN CONCAT(er.thread_key, er.thread_no) END) AS open_threads,
                    SUM(er.thread_anomaly IS NOT NULL) AS anomalies
             FROM extracted_records er JOIN templates t ON t.id = er.template_id
             WHERE t.application_id = ? AND er.thread_key IS NOT NULL"
        );
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch() ?: [];

        $recent = $this->db->prepare(
            'SELECT er.id, er.thread_key, er.thread_no, er.thread_sequence, er.thread_event, er.thread_state, er.thread_anomaly,
                    re.subject, re.received_at
             FROM extracted_records er
             JOIN templates t ON t.id = er.template_id
             JOIN raw_emails re ON re.id = er.raw_email_id
             WHERE t.application_id = ? AND er.thread_key IS NOT NULL
             ORDER BY re.received_at DESC, er.id DESC LIMIT 80'
        );
        $recent->execute([$applicationId]);
        $groups = [];
        foreach ($recent->fetchAll() as $r) {
            $groups[$r['thread_key'] . '-' . $r['thread_no']][] = $r;
        }

        $examples = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn (array $a, array $b): int => (int) $a['thread_sequence'] <=> (int) $b['thread_sequence']);
            $examples[] = [
                'label' => $this->exampleLabel((int) $members[0]['id'], $applicationId),
                'closed' => $members[0]['thread_state'] === 'closed',
                'events' => array_map(static fn (array $m): array => [
                    'event' => (string) $m['thread_event'],
                    'subject' => (string) $m['subject'],
                    'sent_at' => $m['received_at'] !== null
                        ? (new \DateTimeImmutable($m['received_at']))->setTimezone(new \DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i')
                        : null,
                    'anomaly' => $m['thread_anomaly'],
                ], $members),
            ];
            if (count($examples) >= 3) {
                break;
            }
        }

        return [
            'records' => (int) ($row['records'] ?? 0),
            'threads' => (int) ($row['threads'] ?? 0),
            'closed' => (int) ($row['closed'] ?? 0),
            'open' => (int) ($row['open_threads'] ?? 0),
            'anomalies' => (int) ($row['anomalies'] ?? 0),
            'examples' => $examples,
        ];
    }

    /** Läsbar etikett för en tråd: nyckelfältens värden för ett av dess records. */
    private function exampleLabel(int $recordId, int $applicationId): string
    {
        $app = $this->db->prepare('SELECT thread_key_fields FROM applications WHERE id = ?');
        $app->execute([$applicationId]);
        $names = self::splitList((string) $app->fetchColumn());
        if ($names === []) {
            return '';
        }

        $stmt = $this->db->prepare('SELECT field_name, field_value FROM extracted_fields WHERE extracted_record_id = ? AND matched = 1');
        $stmt->execute([$recordId]);
        $fields = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return implode(' · ', array_filter(array_map(static fn (string $n): string => (string) ($fields[$n] ?? ''), $names)));
    }
}
