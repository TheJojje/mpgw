<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\View\ViewRenderer;
use Mpgw\Service\AlertPresenter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = fn (string $sql) => $this->db->query($sql);

        $openAlertsCount = (int) $q("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn();
        $mails24 = (int) $q('SELECT COUNT(*) FROM raw_emails WHERE fetched_at >= NOW() - INTERVAL 24 HOUR')->fetchColumn();
        $partial24 = (int) $q("SELECT COUNT(*) FROM extracted_records WHERE status = 'partial' AND processed_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        $rate7 = $q('SELECT AVG(match_rate) AS r, COUNT(*) AS n, SUM(status = \'success\') AS ok FROM extracted_records WHERE processed_at >= NOW() - INTERVAL 7 DAY')->fetch();
        $threads = $q("SELECT COUNT(DISTINCT CASE WHEN thread_state = 'open' THEN CONCAT(thread_key, thread_no) END) AS open_threads,
                              COUNT(DISTINCT CASE WHEN thread_state = 'closed' THEN CONCAT(thread_key, thread_no) END) AS closed_threads,
                              SUM(thread_anomaly IS NOT NULL) AS anomalies
                       FROM extracted_records WHERE thread_key IS NOT NULL")->fetch();
        $lastPolled = $q('SELECT MAX(last_polled_at) FROM mailboxes WHERE is_active = 1')->fetchColumn();
        $mailboxCount = (int) $q('SELECT COUNT(*) FROM mailboxes WHERE is_active = 1')->fetchColumn();

        $todoStmt = $this->db->query(
            "SELECT a.*, re.uuid AS raw_email_uuid, re.subject AS raw_email_subject, t.name AS template_name, app.name AS application_name
             FROM alerts a
             LEFT JOIN raw_emails re ON re.id = a.raw_email_id
             LEFT JOIN templates t ON t.id = a.template_id
             LEFT JOIN applications app ON app.id = a.application_id
             WHERE a.status = 'open'
             ORDER BY a.created_at DESC LIMIT 5"
        );
        $todo = array_map(static fn (array $a): array => $a + AlertPresenter::describe($a), $todoStmt->fetchAll());

        $recent = $this->db->query(
            'SELECT re.uuid, re.fetched_at, re.received_at, re.from_address, re.subject, er.status AS match_status
             FROM raw_emails re
             LEFT JOIN extracted_records er ON er.raw_email_id = re.id
             ORDER BY COALESCE(re.received_at, re.fetched_at) DESC LIMIT 5'
        )->fetchAll();
        foreach ($recent as &$mail) {
            $mail['sent_at'] = $this->toStockholm($mail['received_at']);
        }
        unset($mail);

        $apps = $this->db->query(
            "SELECT a.id, a.name, a.is_active,
                (SELECT COUNT(*) FROM mailboxes m WHERE m.application_id = a.id) AS mailbox_count,
                (SELECT COUNT(*) FROM templates t WHERE t.application_id = a.id) AS template_count,
                (SELECT COUNT(*) FROM alerts al WHERE al.application_id = a.id AND al.status = 'open') AS open_alerts,
                (SELECT COUNT(*) FROM raw_emails re JOIN mailboxes mb ON mb.id = re.mailbox_id
                   WHERE mb.application_id = a.id AND re.fetched_at >= NOW() - INTERVAL 30 DAY) AS mails30,
                (a.thread_key_fields IS NOT NULL AND a.thread_key_fields <> '') AS threads_on
             FROM applications a WHERE a.is_active = 1 ORDER BY a.name"
        )->fetchAll();
        $broken = array_column($this->applicationsNeedingAttention(), 'reason', 'id');
        foreach ($apps as &$app) {
            $app['mailbox_error'] = ($broken[$app['id']] ?? null) === 'Mailbox går inte att nå';
        }
        unset($app);

        return $this->view->render($request, $response, 'dashboard.twig', [
            'todo' => $todo,
            'open_alerts_count' => $openAlertsCount,
            'mails_24h' => $mails24,
            'partial_24h' => $partial24,
            'match_rate_7d' => $rate7['n'] > 0 ? (int) round(((float) $rate7['r']) * 100) : null,
            'records_7d' => (int) $rate7['n'],
            'success_7d' => (int) ($rate7['ok'] ?? 0),
            'open_threads' => (int) ($threads['open_threads'] ?? 0),
            'closed_threads' => (int) ($threads['closed_threads'] ?? 0),
            'anomalies' => (int) ($threads['anomalies'] ?? 0),
            // last_polled_at skrivs av MySQL (NOW(), serverns lokala tid) - ingen tidszonskonvertering.
            'last_polled' => $lastPolled ? substr((string) $lastPolled, 0, 16) : null,
            'mailbox_count' => $mailboxCount,
            'recent_emails' => $recent,
            'applications' => $apps,
        ]);
    }

    private function toStockholm(?string $dateTime): ?string
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        return (new \DateTimeImmutable($dateTime))->setTimezone(new \DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i');
    }

    /**
     * Applikationer med minst en öppen larm, eller vars senaste
     * ingest_log-händelse för någon av dess mailboxar är 'imap_error' (dvs
     * samma "senaste pollningen misslyckades"-signal som
     * MailboxesController::lastError() visar per mailbox, bara aggregerad
     * applikation-för-applikation här för dashboardens genväg).
     */
    private function applicationsNeedingAttention(): array
    {
        $openAlertApplications = $this->db->query(
            "SELECT app.id, app.name, 'Öppet larm' AS reason
             FROM applications app
             INNER JOIN alerts a ON a.application_id = app.id AND a.status = 'open'
             GROUP BY app.id, app.name"
        )->fetchAll();

        $failedMailboxApplications = $this->db->query(
            "SELECT app.id, app.name, 'Mailbox går inte att nå' AS reason
             FROM applications app
             INNER JOIN mailboxes m ON m.application_id = app.id AND m.is_active = 1
             WHERE (
                 SELECT il.event FROM ingest_log il
                 WHERE il.mailbox_id = m.id
                 ORDER BY il.created_at DESC LIMIT 1
             ) = 'imap_error'
             GROUP BY app.id, app.name"
        )->fetchAll();

        $byId = [];
        foreach (array_merge($openAlertApplications, $failedMailboxApplications) as $row) {
            $byId[$row['id']] ??= $row;
        }

        return array_values($byId);
    }
}
