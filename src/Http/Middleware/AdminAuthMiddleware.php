<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuthorizationService;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enkel sessionsbaserad inloggningskontroll for adminvyn. Anvands ENDAST
 * for manniskor i webb-UI:t - externa system autentiserar via API-nyckel
 * (se ApiKeyAuthMiddleware), inte denna.
 */
final class AdminAuthMiddleware implements Middleware
{
    // Hur ofta (sekunder) en inloggad sessions konto och rattigheter kontrolleras mot databasen.
    private const REVALIDATE_SECONDS = 60;

    public function __construct(
        private readonly ?UserRepository $users = null,
        private readonly ?AuthorizationService $authz = null,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!empty($_SESSION['admin_user_id'])) {
            // En inaktiverad/borttagen anvandare ska inte kunna fortsatta anvanda en
            // gammal session, och andrade roller ska sla igenom utan ny inloggning.
            if ($this->users !== null && time() - (int) ($_SESSION['authz_primed_at'] ?? 0) >= self::REVALIDATE_SECONDS) {
                $user = $this->users->find((int) $_SESSION['admin_user_id']);
                if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
                    $_SESSION = [];
                    session_destroy();
                    $response = (new ResponseFactory())->createResponse(302);
                    return $response->withHeader('Location', '/admin/login');
                }
                $this->authz?->primeSessionForUser((int) $user['id']);
            }

            return $handler->handle($request);
        }

        $response = (new ResponseFactory())->createResponse(302);
        return $response->withHeader('Location', '/admin/login');
    }
}
