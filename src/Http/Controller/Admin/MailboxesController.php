<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\IngestLogRepository;
use Mpgw\Infrastructure\Db\Repository\MailboxRepository;
use Mpgw\Infrastructure\Imap\WebklexImapClient;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\CredentialCipher;
use Mpgw\Support\FlashMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Redigering/radering av en enskild mailbox. Skapande sker fortfarande
 * tillsammans med applikationen i ApplicationsController::create.
 */
final class MailboxesController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly MailboxRepository $mailboxes,
        private readonly ApplicationRepository $applications,
        private readonly CredentialCipher $cipher,
        private readonly WebklexImapClient $imapClient,
        private readonly IngestLogRepository $ingestLog,
        private readonly AuditLogger $audit,
    ) {
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $mailbox = $this->mailboxes->find((int) $args['id']);
        if ($mailbox === null) {
            return $response->withStatus(404);
        }

        $application = $this->applications->find((int) $mailbox['application_id']);

        return $this->view->render($request, $response, 'mailboxes/edit.twig', [
            'mailbox' => $mailbox,
            'application' => $application,
            'lastError' => $this->lastError((int) $mailbox['id']),
        ]);
    }

    /**
     * Senaste felet for mailboxen, om det ar nyare an senast lyckade
     * hamtning - anvands for att visa en varning i redigeringsvyn nar
     * servern inte gick att na eller auth misslyckades vid pollning.
     */
    private function lastError(int $mailboxId): ?array
    {
        $entries = $this->ingestLog->recentForMailbox($mailboxId, 20);
        foreach ($entries as $entry) {
            if ($entry['event'] === 'fetched') {
                return null;
            }
            if ($entry['event'] === 'imap_error') {
                return $entry;
            }
        }

        return null;
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $mailboxId = (int) $args['id'];
        $mailbox = $this->mailboxes->find($mailboxId);
        if ($mailbox === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $host = trim((string) ($data['mailbox_host'] ?? ''));
        $username = trim((string) ($data['mailbox_username'] ?? ''));

        if ($host === '' || $username === '') {
            $application = $this->applications->find((int) $mailbox['application_id']);
            return $this->view->render($request, $response->withStatus(422), 'mailboxes/edit.twig', [
                'mailbox' => $mailbox,
                'application' => $application,
                'error' => 'Host och användarnamn krävs.',
            ]);
        }

        $this->mailboxes->update(
            $mailboxId,
            $host,
            (int) $data['mailbox_port'],
            (string) $data['mailbox_encryption'],
            $username,
            (string) ($data['mailbox_folder'] ?: 'INBOX'),
        );

        // Losenordet ar valfritt att andra - lamnas faltet tomt behalls det befintliga.
        $newPassword = (string) ($data['mailbox_password'] ?? '');
        if ($newPassword !== '') {
            $encrypted = $this->cipher->encrypt($newPassword);
            $this->mailboxes->updateSecret($mailboxId, $encrypted['ciphertext'], $encrypted['nonce']);
        }

        // Losenordet loggas aldrig - bara icke-kansliga anslutningsfalt.
        $this->audit->log('mailboxes', 'updated', $this->currentUserId(), 'mailbox', $mailboxId, "Mailbox {$host} uppdaterades", [
            'host' => $host,
            'port' => (int) $data['mailbox_port'],
            'encryption' => (string) $data['mailbox_encryption'],
            'username' => $username,
            'folder' => (string) ($data['mailbox_folder'] ?: 'INBOX'),
        ]);

        FlashMessage::success("Mailboxen {$host} uppdaterades.");

        return $response->withHeader('Location', '/admin/applications/' . $mailbox['application_id'])->withStatus(302);
    }

    /**
     * AJAX: testar IMAP-uppgifterna direkt mot servern, utan att spara
     * nagot. Anvander vardena som star i formularet just nu (inte det som
     * ligger sparat i databasen) sa att man kan verifiera andringar innan
     * de sparas. Lamnas losenordsfaltet tomt anvands det redan sparade.
     */
    public function testConnection(Request $request, Response $response, array $args): Response
    {
        $mailboxId = (int) $args['id'];
        $mailbox = $this->mailboxes->find($mailboxId);
        if ($mailbox === null) {
            return $this->json($response->withStatus(404), ['success' => false, 'message' => 'Mailboxen hittades inte.']);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $this->json($response->withStatus(400), ['success' => false, 'message' => 'Ogiltig session (CSRF), ladda om sidan.']);
        }

        $host = trim((string) ($data['mailbox_host'] ?? ''));
        $username = trim((string) ($data['mailbox_username'] ?? ''));
        $port = (int) ($data['mailbox_port'] ?? 0);
        $encryption = (string) ($data['mailbox_encryption'] ?? 'ssl');
        $folder = (string) ($data['mailbox_folder'] ?: 'INBOX');

        if ($host === '' || $username === '' || $port <= 0) {
            return $this->json($response->withStatus(422), [
                'success' => false,
                'message' => 'Host, port och användarnamn krävs för att testa anslutningen.',
            ]);
        }

        $submittedPassword = (string) ($data['mailbox_password'] ?? '');
        if ($submittedPassword !== '') {
            $password = $submittedPassword;
        } else {
            $password = $this->cipher->decrypt($mailbox['secret_ciphertext'], $mailbox['secret_nonce']);
        }

        try {
            $this->imapClient->testConnection($host, $port, $encryption, $username, $password, $folder);
        } catch (Throwable $e) {
            // WebklexImapClient::testConnection oversatter redan biblioteksfel
            // till ett meddelande som gar att visa direkt for anvandaren.
            return $this->json($response, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->json($response, [
            'success' => true,
            'message' => 'Anslutningen lyckades - host, port, inloggning och mapp fungerar.',
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $mailboxId = (int) $args['id'];
        $mailbox = $this->mailboxes->find($mailboxId);
        if ($mailbox === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->mailboxes->deactivate($mailboxId);

        $this->audit->log('mailboxes', 'deactivated', $this->currentUserId(), 'mailbox', $mailboxId, "Mailbox {$mailbox['host']} inaktiverades", [
            'host' => $mailbox['host'],
        ]);

        FlashMessage::success("Mailboxen {$mailbox['host']} togs bort.");

        return $response->withHeader('Location', '/admin/applications/' . $mailbox['application_id'])->withStatus(302);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }
}
