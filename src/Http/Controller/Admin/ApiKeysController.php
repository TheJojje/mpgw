<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApiKeyRepository;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ApiKeysController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly ApplicationRepository $applications,
        private readonly ApiKeyRepository $apiKeys,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'api_keys/index.twig', ['keys' => $this->apiKeys->all()]);
    }

    public function newForm(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'api_keys/new.twig', ['applications' => $this->applications->allActive()]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $formData = ['applications' => $this->applications->allActive()];

        $consumerName = trim((string) ($data['consumer_name'] ?? ''));
        if ($consumerName === '') {
            return $this->view->render($request, $response->withStatus(422), 'api_keys/new.twig', $formData + ['error' => 'Konsumentnamn krävs.']);
        }

        // En nyckel ar alltid knuten till exakt en applikation - utan detta
        // skulle scopes som "records:read" lasa data for ALLA applikationer.
        $applicationId = (int) ($data['application_id'] ?? 0);
        if ($applicationId <= 0 || $this->applications->find($applicationId) === null) {
            return $this->view->render($request, $response->withStatus(422), 'api_keys/new.twig', $formData + ['error' => 'Välj en giltig applikation.']);
        }

        // Nyckelformat: mpgw_<24 slumpmassiga hex-tecken>. Prefixet lagras i
        // klartext for identifiering i UI, hela nyckeln endast som hash.
        $secret = bin2hex(random_bytes(24));
        $fullKey = 'mpgw_' . $secret;
        $prefix = substr($fullKey, 0, 12);
        $hash = hash('sha256', $fullKey);

        $scopes = array_values((array) ($data['scopes'] ?? []));
        $rateLimitRaw = $data['rate_limit_per_minute'] ?? '';
        $rateLimit = $rateLimitRaw !== '' ? (int) $rateLimitRaw : null;

        $stmt = $this->db->prepare(
            'INSERT INTO api_keys (consumer_name, application_id, key_prefix, key_hash, scopes, rate_limit_per_minute)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$consumerName, $applicationId, $prefix, $hash, json_encode($scopes), $rateLimit]);
        $apiKeyId = (int) $this->db->lastInsertId();

        // Nyckelns hash/prefix loggas aldrig - bara vem den ar for och vilka scopes den har.
        $this->audit->log('api_keys', 'created', $this->currentUserId(), 'api_key', $apiKeyId, "API-nyckel för {$consumerName} skapades", [
            'consumer_name' => $consumerName,
            'application_id' => $applicationId,
            'scopes' => $scopes,
            'rate_limit_per_minute' => $rateLimit,
        ]);

        FlashMessage::success("API-nyckeln för {$consumerName} skapades.");

        return $this->view->render($request, $response, 'api_keys/index.twig', [
            'keys' => $this->apiKeys->all(),
            'new_key' => $fullKey,
        ]);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $key = $this->apiKeys->find((int) $args['id']);
        if ($key === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($request, $response, 'api_keys/edit.twig', ['key' => $key]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $keyId = (int) $args['id'];
        $key = $this->apiKeys->find($keyId);
        if ($key === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $consumerName = trim((string) ($data['consumer_name'] ?? ''));
        if ($consumerName === '') {
            return $this->view->render($request, $response->withStatus(422), 'api_keys/edit.twig', [
                'key' => $key,
                'error' => 'Konsumentnamn krävs.',
            ]);
        }

        $scopes = array_values((array) ($data['scopes'] ?? []));
        $rateLimitRaw = $data['rate_limit_per_minute'] ?? '';
        $rateLimit = $rateLimitRaw !== '' ? (int) $rateLimitRaw : null;

        $this->apiKeys->update($keyId, $consumerName, $scopes, $rateLimit);

        // Nyckelns hash/prefix loggas aldrig - bara vem den ar for och vilka scopes den har.
        $this->audit->log('api_keys', 'updated', $this->currentUserId(), 'api_key', $keyId, "API-nyckel för {$consumerName} uppdaterades", [
            'consumer_name' => $consumerName,
            'scopes' => $scopes,
            'rate_limit_per_minute' => $rateLimit,
        ]);

        FlashMessage::success("API-nyckeln för {$consumerName} uppdaterades.");

        return $response->withHeader('Location', '/admin/api-keys')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $keyId = (int) $args['id'];
        $key = $this->apiKeys->find($keyId);
        if ($key === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->apiKeys->deactivate($keyId);

        $this->audit->log('api_keys', 'deactivated', $this->currentUserId(), 'api_key', $keyId, "API-nyckel för {$key['consumer_name']} inaktiverades", [
            'consumer_name' => $key['consumer_name'],
        ]);

        FlashMessage::success("API-nyckeln för {$key['consumer_name']} togs bort.");

        return $response->withHeader('Location', '/admin/api-keys')->withStatus(302);
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $keyId = (int) $args['id'];
        $key = $this->apiKeys->find($keyId);
        if ($key === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->apiKeys->reactivate($keyId);

        $this->audit->log('api_keys', 'reactivated', $this->currentUserId(), 'api_key', $keyId, "API-nyckel för {$key['consumer_name']} återaktiverades", [
            'consumer_name' => $key['consumer_name'],
        ]);

        FlashMessage::success("API-nyckeln för {$key['consumer_name']} återaktiverades.");

        return $response->withHeader('Location', '/admin/api-keys')->withStatus(302);
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }
}
