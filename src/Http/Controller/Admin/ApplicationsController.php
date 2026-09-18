<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\MailboxRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\CredentialCipher;
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
            $data['description'] ?: null,
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
            'description' => $data['description'] ?: null,
            'alert_email' => $data['alert_email'] ?: null,
            'retention_days' => $retentionDays,
            'raw_email_retention_days' => $rawEmailRetentionDays,
        ]);

        FlashMessage::success("Applikationen {$name} skapades.");

        return $response->withHeader('Location', '/admin/applications/' . $applicationId)->withStatus(302);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $application = $this->applications->find((int) $args['id']);
        if ($application === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($request, $response, 'applications/edit.twig', ['application' => $application]);
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

            return $this->view->render($request, $response->withStatus(422), 'applications/edit.twig', [
                'application' => $application,
                'error' => 'Namn krävs.',
            ]);
        }

        $retentionDays = ($data['retention_days'] ?? '') !== '' ? (int) $data['retention_days'] : null;
        $rawEmailRetentionDays = ($data['raw_email_retention_days'] ?? '') !== '' ? (int) $data['raw_email_retention_days'] : null;

        $this->applications->update(
            $applicationId,
            $name,
            $data['description'] ?: null,
            $data['alert_email'] ?: null,
            $data['alert_webhook_url'] ?: null,
            $retentionDays,
            $rawEmailRetentionDays,
        );

        $this->audit->log('applications', 'updated', $this->currentUserId(), 'application', $applicationId, "Applikation {$name} uppdaterades", [
            'name' => $name,
            'description' => $data['description'] ?: null,
            'alert_email' => $data['alert_email'] ?: null,
            'alert_webhook_url' => $data['alert_webhook_url'] ?: null,
            'retention_days' => $retentionDays,
            'raw_email_retention_days' => $rawEmailRetentionDays,
        ]);

        // Redigering fran listan ska landa kvar i listan; den fristaende
        // edit-sidan behaller sitt vanliga beteende (tillbaka till show-sidan).
        $redirectTo = ($data['redirect_to'] ?? null) === 'list'
            ? '/admin/applications'
            : '/admin/applications/' . $applicationId;

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
        $applicationId = (int) $args['id'];
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return $response->withStatus(404);
        }

        // Samma "visa=alla"-monster som applications/index.twig: bara aktiva
        // mallar visas som standard, en inaktiverad mall ska kannas borttagen.
        $showAllTemplates = ($request->getQueryParams()['visa'] ?? null) === 'alla';

        $stmt = $this->db->prepare(
            "SELECT m.*,
                (SELECT il.event FROM ingest_log il WHERE il.mailbox_id = m.id ORDER BY il.created_at DESC LIMIT 1) AS last_ingest_event
             FROM mailboxes m WHERE m.application_id = ?"
        );
        $stmt->execute([$applicationId]);

        $templatesStmt = $this->db->prepare(
            'SELECT t.*,
                (SELECT COUNT(*) FROM extraction_rules r WHERE r.template_id = t.id) AS rule_count,
                (SELECT AVG(er.match_rate) FROM extracted_records er WHERE er.template_id = t.id) AS avg_match_rate
             FROM templates t WHERE t.application_id = ?'
            . ($showAllTemplates ? '' : ' AND t.is_active = 1') .
            ' ORDER BY t.priority ASC'
        );
        $templatesStmt->execute([$applicationId]);

        return $this->view->render($request, $response, 'applications/show.twig', [
            'application' => $application,
            'mailboxes' => $stmt->fetchAll(),
            'templates' => $templatesStmt->fetchAll(),
            'show_all_templates' => $showAllTemplates,
        ]);
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
}
