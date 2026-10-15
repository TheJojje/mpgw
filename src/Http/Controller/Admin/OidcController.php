<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Jumbojett\OpenIDConnectClient;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\RoleRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Mpgw\Service\OidcRoleMapper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SSO via authentik (OIDC, authorization code + PKCE). Atkomst kraver
 * gruppmedlemskap; roll satts fran grupp vid varje inloggning.
 */
final class OidcController
{
    /** @param array<string,mixed> $config config['oidc'] */
    public function __construct(
        private readonly array $config,
        private readonly ViewRenderer $view,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuthorizationService $authz,
        private readonly AuditLogger $audit,
        private readonly OidcRoleMapper $mapper,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        if (!$this->config['enabled']) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        // authenticate() omdirigerar till authentik och avslutar scriptet.
        $this->client()->authenticate();

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    public function callback(Request $request, Response $response): Response
    {
        if (!$this->config['enabled']) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        try {
            $oidc = $this->client();
            $oidc->authenticate();

            $sub = (string) $oidc->getVerifiedClaims('sub');
            $email = strtolower(trim((string) ($oidc->getVerifiedClaims('email') ?? '')));
            $name = trim((string) ($oidc->getVerifiedClaims('name') ?? ''));
            $groups = $oidc->getVerifiedClaims('groups');
            if ($groups === null) {
                $groups = $oidc->requestUserInfo('groups');
            }
            $groups = is_array($groups) ? array_values($groups) : [];
            $idToken = $oidc->getIdToken();
        } catch (\Throwable $e) {
            $this->audit->log('auth', 'login_failed', null, 'user', null, 'SSO-inloggning misslyckades: ' . $e->getMessage(), ['ip' => $ip, 'method' => 'oidc'], level: 'warning');

            return $this->deny($request, $response, 401, 'Inloggningen via authentik misslyckades. Försök igen.');
        }

        if ($sub === '' || $email === '') {
            return $this->deny($request, $response, 401, 'Authentik skickade ingen e-postadress. Kontrollera scope "email".');
        }

        $roleSlug = $this->mapper->roleSlug($groups);
        if ($roleSlug === null) {
            $this->audit->log('auth', 'login_denied', null, 'user', null, "SSO nekad (saknar grupp): {$email}", ['email' => $email, 'ip' => $ip, 'method' => 'oidc'], level: 'warning');

            return $this->deny($request, $response, 403, 'Du saknar behörighet till mpgw (kräver gruppmedlemskap).');
        }

        $role = $this->roles->findBySlug($roleSlug);
        if ($role === null) {
            return $this->deny($request, $response, 500, "Rollen '{$roleSlug}' finns inte i mpgw.");
        }

        $user = $this->users->findByOidcSub($sub) ?? $this->users->findByEmailAnyStatus($email);

        if ($user !== null && (int) $user['is_active'] !== 1) {
            $this->audit->log('auth', 'login_denied', (int) $user['id'], 'user', (int) $user['id'], "SSO nekad (inaktiv användare): {$email}", ['ip' => $ip, 'method' => 'oidc'], level: 'warning');

            return $this->deny($request, $response, 403, 'Kontot är inaktiverat i mpgw.');
        }

        if ($user === null) {
            // Slumpad, oanvandbar hash - SSO-konton loggar inte in med losenord.
            $userId = $this->users->create($name !== '' ? $name : $email, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
            $this->audit->log('users', 'create', $userId, 'user', $userId, "Användare skapad via SSO: {$email}", ['email' => $email, 'method' => 'oidc']);
        } else {
            $userId = (int) $user['id'];
        }

        if (($user['oidc_sub'] ?? null) !== $sub) {
            $this->users->setOidcSub($userId, $sub);
        }

        $this->users->setRoles($userId, [(int) $role['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $userId;
        $_SESSION['oidc_id_token'] = $idToken;

        $this->authz->primeSessionForUser($userId);
        $this->users->touchLastLogin($userId);
        $this->audit->log('auth', 'login', $userId, 'user', $userId, ($name !== '' ? $name : $email) . ' loggade in via SSO', ['email' => $email, 'ip' => $ip, 'method' => 'oidc', 'role' => $roleSlug]);

        return $response->withHeader('Location', '/admin')->withStatus(302);
    }

    private function client(): OpenIDConnectClient
    {
        $oidc = new OpenIDConnectClient($this->config['issuer'], $this->config['client_id'], $this->config['client_secret']);
        $oidc->setRedirectURL($this->config['redirect_uri']);
        $oidc->setCodeChallengeMethod('S256');
        $oidc->addScope(['openid', 'profile', 'email', 'groups']);

        return $oidc;
    }

    private function deny(Request $request, Response $response, int $status, string $message): Response
    {
        return $this->view->render($request, $response->withStatus($status), 'login.twig', ['error' => $message]);
    }
}
