<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\View\ViewRenderer;
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
        $applicationsCount = (int) $this->db->query('SELECT COUNT(*) FROM applications')->fetchColumn();
        $recentEmailsCount = (int) $this->db->query(
            'SELECT COUNT(*) FROM raw_emails WHERE fetched_at >= NOW() - INTERVAL 24 HOUR'
        )->fetchColumn();
        $openAlertsCount = (int) $this->db->query("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn();

        $stmt = $this->db->query(
            'SELECT re.uuid, re.fetched_at, re.from_address, re.subject, er.status AS match_status
             FROM raw_emails re
             LEFT JOIN extracted_records er ON er.raw_email_id = re.id
             ORDER BY re.fetched_at DESC LIMIT 5'
        );

        $recentAlertsStmt = $this->db->query(
            'SELECT a.id, a.type, a.severity, a.status, a.created_at, app.name AS application_name, app.id AS application_id
             FROM alerts a
             LEFT JOIN applications app ON app.id = a.application_id
             ORDER BY a.created_at DESC LIMIT 5'
        );

        return $this->view->render($request, $response, 'dashboard.twig', [
            'applications_count' => $applicationsCount,
            'recent_emails_count' => $recentEmailsCount,
            'open_alerts_count' => $openAlertsCount,
            'recent_emails' => $stmt->fetchAll(),
            'recent_alerts' => $recentAlertsStmt->fetchAll(),
            'attention_applications' => $this->applicationsNeedingAttention(),
        ]);
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
