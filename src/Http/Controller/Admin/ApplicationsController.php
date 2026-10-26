<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\MailboxRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Service\AlertPresenter;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\CredentialCipher;
use Mpgw\Service\ThreadService;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ApplicationsController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly ApplicationRepository $applications,
        private readonly MailboxRepository $mailboxes,
        private readonly TemplateRepository $templates,
        private readonly CredentialCipher $cipher,
        private readonly AuditLogger $audit,
        private readonly ThreadService $threads,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        // Visar bara aktiva applikationer som standard - en raderad
        // (soft-deleted) applikation ska kannas borttagen. ?visa=alla listar
        // aven inaktiva, ifall nagon vill kolla/aterstalla en som raderats
        // av misstag.
        $showAll = ($request->getQueryParams()['visa'] ?? null) === 'alla';
        $errorParam = $request->getQueryParams()['error'] ?? null;

        $stmt = $this->db->query(
            'SELECT a.*,
                (SELECT COUNT(*) FROM mailboxes m WHERE m.application_id = a.id) AS mailbox_count,
                (SELECT COUNT(*) FROM templates t WHERE t.application_id = a.id) AS template_count
             FROM applications a
             ' . ($showAll ? '' : 'WHERE a.is_active = 1') . '
             ORDER BY a.name'
        );

        return $this->view->render($request, $response, 'applications/index.twig', [
            'applications' => $stmt->fetchAll(),
            'show_all' => $showAll,
            'error' => $errorParam === 'name_required' ? 'Namn krävs.' : null,
        ]);
    }

    public function newForm(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'applications/new.twig');
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->view->render($request, $response->withStatus(422), 'applications/new.twig', ['error' => 'Namn krävs.']);
        }

        $retentionDays = ($data['retention_days'] ?? '') !== '' ? (int) $data['retention_days'] : null;
        $rawEmailRetentionDays = ($data['raw_email_retention_days'] ?? '') !== '' ? (int) $data['raw_email_retention_days'] : null;

        $applicationId = $this->applications->create(
            $name,
            ($data['description'] ?? null) ?: null,
            $data['alert_email'] ?: null,
            $data['alert_webhook_url'] ?: null,
            $retentionDays,
            $rawEmailRetentionDays,
        );

        $encrypted = $this->cipher->encrypt((string) ($data['mailbox_password'] ?? ''));

        $this->mailboxes->create(
            $applicationId,
            (string) $data['mailbox_host'],
            (int) $data['mailbox_port'],
            (string) $data['mailbox_encryption'],
            (string) $data['mailbox_username'],
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            (string) ($data['mailbox_folder'] ?: 'INBOX'),
        );

        $this->audit->log('applications', 'created', $this->currentUserId(), 'application', $applicationId, "Applikation {$name} skapades", [
            'name' => $name,
            'description' => ($data['description'] ?? null) ?: null,
            'alert_email' => $data['alert_email'] ?: null,
            'retention_days' => $retentionDays,
            'raw_email_retention_days' => $rawEmailRetentionDays,
        ]);

        FlashMessage::success("Applikationen {$name} skapades.");

        return $response->withHeader('Location', '/admin/applications/' . $applicationId)->withStatus(302);
    }

    /** Visa och redigera ar en och samma sida (flikar); den gamla adressen leder dit. */
    public function editForm(Request $request, Response $response, array $args): Response
    {
        return $response->withHeader('Location', '/admin/applications/' . (int) $args['id'] . '#settings')->withStatus(302);
    }

    /**
     * Data till redigeringssidan: overblick, mailboxar och underlag for
     * tradinstallningarna (faltnamn/varden att foresla + sammanfattning).
     *
     * @return array<string,mixed>
     */
    private function editViewData(array $application, ?string $error = null, bool $showAllTemplates = false): array
    {
        $applicationId = (int) $application['id'];

        $mailboxStmt = $this->db->prepare(
            "SELECT m.*,
                (SELECT il.event FROM ingest_log il WHERE il.mailbox_id = m.id ORDER BY il.created_at DESC LIMIT 1) AS last_ingest_event
             FROM mailboxes m WHERE m.application_id = ?"
        );
        $mailboxStmt->execute([$applicationId]);

        $templateCount = $this->db->prepare('SELECT COUNT(*) FROM templates WHERE application_id = ?');
        $templateCount->execute([$applicationId]);

        $mailCount = $this->db->prepare(
            'SELECT COUNT(*) FROM raw_emails re JOIN mailboxes mb ON mb.id = re.mailbox_id
             WHERE mb.application_id = ? AND re.fetched_at >= (NOW() - INTERVAL 30 DAY)'
        );
        $mailCount->execute([$applicationId]);

        // Faltnamn som finns i applikationens mallar - foreslas som nyckel-/handelsefalt.
        $fieldNames = $this->db->prepare(
            'SELECT DISTINCT r.field_name FROM extraction_rules r JOIN templates t ON t.id = r.template_id
             WHERE t.application_id = ? AND r.is_active = 1 ORDER BY r.field_name'
        );
        $fieldNames->execute([$applicationId]);

        // Varden som faktiskt forekommit per falt (bara falt med fa olika varden) - foreslas som oppna/stang-varden.
        $valueStmt = $this->db->prepare(
            'SELECT f.field_name, f.field_value
             FROM extracted_fields f
             JOIN extracted_records er ON er.id = f.extracted_record_id
             JOIN templates t ON t.id = er.template_id
             WHERE t.application_id = ? AND f.matched = 1 AND f.field_value IS NOT NULL
             GROUP BY f.field_name, f.field_value'
        );
        $valueStmt->execute([$applicationId]);
        $fieldValues = [];
        foreach ($valueStmt->fetchAll() as $row) {
            $fieldValues[$row['field_name']][] = $row['field_value'];
        }
        $fieldValues = array_filter($fieldValues, static fn (array $values): bool => count($values) <= 12);

        $templatesStmt = $this->db->prepare(
            'SELECT t.*,
                (SELECT COUNT(*) FROM extraction_rules r WHERE r.template_id = t.id AND r.is_active = 1) AS rule_count,
                (SELECT AVG(er.match_rate) FROM extracted_records er WHERE er.template_id = t.id) AS avg_match_rate
             FROM templates t WHERE t.application_id = ?'
            . ($showAllTemplates ? '' : ' AND t.is_active = 1') .
            ' ORDER BY t.priority ASC'
        );
        $templatesStmt->execute([$applicationId]);

        $alertStmt = $this->db->prepare(
            "SELECT a.*, re.uuid AS raw_email_uuid, re.subject AS raw_email_subject, t.name AS template_name
             FROM alerts a
             LEFT JOIN raw_emails re ON re.id = a.raw_email_id
             LEFT JOIN templates t ON t.id = a.template_id
             WHERE a.application_id = ? AND a.status = 'open'
             ORDER BY a.created_at DESC LIMIT 20"
        );
        $alertStmt->execute([$applicationId]);
        $appAlerts = array_map(static fn (array $a): array => $a + AlertPresenter::describe($a), $alertStmt->fetchAll());

        $rateStmt = $this->db->prepare(
            'SELECT AVG(er.match_rate) FROM extracted_records er JOIN templates t ON t.id = er.template_id WHERE t.application_id = ?'
        );
        $rateStmt->execute([$applicationId]);
        $avgRate = $rateStmt->fetchColumn();

        $polled = $this->db->prepare('SELECT MAX(last_polled_at) FROM mailboxes WHERE application_id = ?');
        $polled->execute([$applicationId]);
        $lastPolled = $polled->fetchColumn();

        return [
            'application' => $application,
            'error' => $error,
            'templates' => $templatesStmt->fetchAll(),
            'show_all_templates' => $showAllTemplates,
            'app_alerts' => $appAlerts,
            'avg_match_rate' => $avgRate !== null && $avgRate !== false ? (int) round(((float) $avgRate) * 100) : null,
            // last_polled_at skrivs av MySQL (NOW(), serverns lokala tid) - ingen tidszonskonvertering.
            'last_polled' => $lastPolled ? substr((string) $lastPolled, 0, 16) : null,
            'mailboxes' => $mailboxStmt->fetchAll(),
            'template_count' => (int) $templateCount->fetchColumn(),
            'mail_count_30d' => (int) $mailCount->fetchColumn(),
            'field_names' => $fieldNames->fetchAll(PDO::FETCH_COLUMN),
            'field_values' => $fieldValues,
            'thread_summary' => $this->threads->summaryForApplication($applicationId),
        ];
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            // Formularet fran listan skickar redirect_to=list och har ingen
            // egen felvy att rendera om - skicka tillbaka dit med felet som
            // query-param istallet. Det fristaende edit.twig-formularet
            // skickar inte redirect_to alls och far sin vanliga 422-rendering.
            if (($data['redirect_to'] ?? null) === 'list') {
                return $response->withHeader('Location', '/admin/applications?error=name_required')->withStatus(302);
            }

            return $this->view->render($request, $response->withStatus(422), 'applications/show.twig', $this->editViewData($application, 'Namn krävs.'));
        }

        $retentionDays = ($data['retention_days'] ?? '') !== '' ? (int) $data['retention_days'] : null;
        $rawEmailRetentionDays = ($data['raw_email_retention_days'] ?? '') !== '' ? (int) $data['raw_email_retention_days'] : null;
        $alertEmail = ($data['alert_email'] ?? null) ?: null;
        $alertWebhookUrl = ($data['alert_webhook_url'] ?? null) ?: null;

        // Det fullstandiga redigeringsformularet (reglage) skickar settings_form;
        // inline-formularet i listan gor det inte och beter sig som forut.
        $fullForm = isset($data['settings_form']);
        if ($fullForm) {
            $alertEmail = !empty($data['alert_email_on']) ? (trim((string) ($data['alert_email'] ?? '')) ?: null) : null;
            $alertWebhookUrl = !empty($data['alert_webhook_on']) ? (trim((string) ($data['alert_webhook_url'] ?? '')) ?: null) : null;
            $retentionDays = !empty($data['retention_on']) ? max(1, (int) ($data['retention_days'] ?? 90)) : null;
            $rawEmailRetentionDays = !empty($data['raw_retention_on']) ? max(1, (int) ($data['raw_email_retention_days'] ?? 90)) : null;
        }

        $this->applications->update(
            $applicationId,
            $name,
            ($data['description'] ?? null) ?: null,
            $alertEmail,
            $alertWebhookUrl,
            $retentionDays,
            $rawEmailRetentionDays,
        );

        if ($fullForm) {
            $this->applications->updateAlertFlags($applicationId, !empty($data['alert_on_partial']), !empty($data['alert_on_thread_anomaly']));

            $wantActive = !empty($data['is_active']);
            if ($wantActive && !(int) $application['is_active']) {
                $this->applications->reactivate($applicationId);
            } elseif (!$wantActive && (int) $application['is_active']) {
                $this->applications->deactivate($applicationId);
            }
        }

        // Tradinstallningar skickas bara fran den fristaende edit-sidan (inte
        // fran inline-formularet i listan) - rora dem inte om de saknas.
        if (isset($data['thread_config'])) {
            // Reglaget "Anvand tradar" av = ingen tradkonfiguration.
            $threadsOn = !$fullForm || !empty($data['thread_enabled']);
            $threadConfig = [
                'thread_key_fields' => $threadsOn ? $this->csvList($data['thread_key_fields'] ?? '') : null,
                'thread_event_field' => $threadsOn ? (trim((string) ($data['thread_event_field'] ?? '')) ?: null) : null,
                'thread_open_values' => $threadsOn ? $this->csvList($data['thread_open_values'] ?? '') : null,
                'thread_close_values' => $threadsOn ? $this->csvList($data['thread_close_values'] ?? '') : null,
            ];
            if ($threadConfig != array_intersect_key($application, $threadConfig)) {
                $this->applications->updateThreading($applicationId, $threadConfig);
                $recordCount = $this->threads->syncApplication($applicationId);
                $this->audit->log('applications', 'threads_configured', $this->currentUserId(), 'application', $applicationId, "Tråd-inställningar för {$name} ändrades ({$recordCount} records omberäknade)", $threadConfig);
            }
        }

        $this->audit->log('applications', 'updated', $this->currentUserId(), 'application', $applicationId, "Applikation {$name} uppdaterades", [
            'name' => $name,
            'description' => ($data['description'] ?? null) ?: null,
            'alert_email' => $alertEmail,
            'alert_webhook_url' => $alertWebhookUrl,
            'retention_days' => $retentionDays,
            'raw_email_retention_days' => $rawEmailRetentionDays,
        ]);

        // Redigering fran listan ska landa kvar i listan; den fristaende
        // edit-sidan behaller sitt vanliga beteende (tillbaka till show-sidan).
        $redirectTo = ($data['redirect_to'] ?? null) === 'list'
            ? '/admin/applications'
            : '/admin/applications/' . $applicationId . (preg_match('/^[a-z]+$/', (string) ($data['return_tab'] ?? '')) === 1 ? '#' . $data['return_tab'] : '');

        FlashMessage::success("Applikationen {$name} uppdaterades.");

        return $response->withHeader('Location', $redirectTo)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->applications->deactivate($applicationId);

        $this->audit->log('applications', 'deactivated', $this->currentUserId(), 'application', $applicationId, "Applikation {$application['name']} inaktiverades", [
            'name' => $application['name'],
        ]);

        FlashMessage::success("Applikationen {$application['name']} togs bort.");

        return $response->withHeader('Location', '/admin/applications')->withStatus(302);
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->applications->reactivate($applicationId);

        $this->audit->log('applications', 'reactivated', $this->currentUserId(), 'application', $applicationId, "Applikation {$application['name']} återaktiverades", [
            'name' => $application['name'],
        ]);

        FlashMessage::success("Applikationen {$application['name']} återaktiverades.");

        return $response->withHeader('Location', '/admin/applications?visa=alla')->withStatus(302);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $application = $this->applications->find((int) $args['id']);
        if ($application === null) {
            return $response->withStatus(404);
        }

        // Samma "visa=alla"-monster som forut: bara aktiva mallar visas som
        // standard, en inaktiverad mall ska kannas borttagen.
        $showAllTemplates = ($request->getQueryParams()['visa'] ?? null) === 'alla';

        return $this->view->render($request, $response, 'applications/show.twig', $this->editViewData($application, null, $showAllTemplates));
    }

    public function deleteTemplate(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $templateId = (int) $args['templateId'];
        $template = $this->templates->find($templateId);
        if ($template === null || (int) $template['application_id'] !== $applicationId) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->templates->deactivate($templateId);

        $this->audit->log('templates', 'deactivated', $this->currentUserId(), 'template', $templateId, "Mall {$template['name']} inaktiverades", [
            'application_id' => $applicationId,
            'name' => $template['name'],
        ]);

        FlashMessage::success("Mallen {$template['name']} togs bort.");

        return $response->withHeader('Location', '/admin/applications/' . $applicationId)->withStatus(302);
    }

    public function reactivateTemplate(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $templateId = (int) $args['templateId'];
        $template = $this->templates->find($templateId);
        if ($template === null || (int) $template['application_id'] !== $applicationId) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->templates->reactivate($templateId);

        $this->audit->log('templates', 'reactivated', $this->currentUserId(), 'template', $templateId, "Mall {$template['name']} återaktiverades", [
            'application_id' => $applicationId,
            'name' => $template['name'],
        ]);

        FlashMessage::success("Mallen {$template['name']} återaktiverades.");

        return $response->withHeader('Location', '/admin/applications/' . $applicationId . '?visa=alla')->withStatus(302);
    }

    public function newTemplateForm(Request $request, Response $response, array $args): Response
    {
        $application = $this->applications->find((int) $args['id']);
        if ($application === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($request, $response, 'applications/template_new.twig', ['application' => $application]);
    }

    public function createTemplate(Request $request, Response $response, array $args): Response
    {
        $applicationId = (int) $args['id'];
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $templateId = $this->templates->create(
            $applicationId,
            (string) $data['name'],
            $data['from_pattern'] ?: null,
            (string) ($data['from_match_type'] ?: 'contains'),
            $data['subject_regex'] ?: null,
            (int) ($data['priority'] ?: 100),
        );

        $this->audit->log('templates', 'created', $this->currentUserId(), 'template', $templateId, "Mall {$data['name']} skapades", [
            'application_id' => $applicationId,
            'name' => (string) $data['name'],
            'priority' => (int) ($data['priority'] ?: 100),
        ]);

        FlashMessage::success("Mallen {$data['name']} skapades.");

        return $response->withHeader('Location', '/admin/templates/' . $templateId)->withStatus(302);
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }

    private function csvList(mixed $value): ?string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== ''));
        return $items === [] ? null : implode(', ', $items);
    }
}
