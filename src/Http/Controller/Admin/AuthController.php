<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Jumbojett\OpenIDConnectClient;
use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Mpgw\Service\LoginThrottle;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepository $users,
        private readonly AuthorizationService $authz,
        private readonly AuditLogger $audit,
        private readonly LoginThrottle $throttle,
        private readonly array $oidcConfig = [],
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['admin_user_id'])) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }

        return $this->view->render($request, $response, 'login.twig');
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $this->view->render($request, $response->withStatus(400), 'login.twig', ['error' => 'Sessionen gick ut, försök igen.']);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        if ($this->throttle->isLocked($ip, $email)) {
            $this->audit->log(
                'auth',
                'login_locked',
                null,
                'user',
                null,
                "Inloggning blockerad (for manga forsok): {$email}",
                ['email' => $email, 'ip' => $ip],
                level: 'warning',
            );

            return $this->view->render($request, $response->withStatus(429), 'login.twig', [
                'error' => 'For manga misslyckade forsok. Prova igen om en stund.',
            ]);
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->throttle->recordFailure($ip, $email);

            $this->audit->log(
                'auth',
                'login_failed',
                null,
                'user',
                null,
                "Misslyckat inloggningsförsök: {$email}",
                ['email' => $email, 'ip' => $ip],
                level: 'warning',
            );

            return $this->view->render($request, $response->withStatus(401), 'login.twig', ['error' => 'Fel e-post eller lösenord.']);
        }

        $this->throttle->clear($ip, $email);

        $userId = (int) $user['id'];

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $userId;

        $this->authz->primeSessionForUser($userId);
        $this->users->touchLastLogin($userId);
        $this->audit->log('auth', 'login', $userId, 'user', $userId, "{$user['name']} loggade in", ['email' => $email, 'ip' => $ip]);

        return $response->withHeader('Location', '/admin')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }

        if (!empty($_SESSION['admin_user_id'])) {
            $userId = (int) $_SESSION['admin_user_id'];
            $user = $this->users->find($userId);
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $this->audit->log('auth', 'logout', $userId, 'user', $userId, ($user['name'] ?? 'Användaren') . ' loggade ut', ['ip' => $ip]);
        }

        $idToken = $_SESSION['oidc_id_token'] ?? null;

        $_SESSION = [];
        session_destroy();

        // Enkel utloggning fran authentik (kraver att post-logout-URI ar tillaten i providern).
        if (is_string($idToken) && !empty($this->oidcConfig['enabled']) && !empty($this->oidcConfig['sso_logout'])) {
            $oidc = new OpenIDConnectClient($this->oidcConfig['issuer'], $this->oidcConfig['client_id'], $this->oidcConfig['client_secret']);
            $oidc->signOut($idToken, $this->oidcConfig['post_logout_redirect_uri']);
        }

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
}
