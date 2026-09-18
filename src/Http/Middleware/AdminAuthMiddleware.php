<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enkel sessionsbaserad inloggningskontroll for adminvyn. Anvands ENDAST
 * for manniskor i webb-UI:t - externa system autentiserar via API-nyckel
 * (se ApiKeyAuthMiddleware), inte denna.
 */
final class AdminAuthMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!empty($_SESSION['admin_user_id'])) {
            return $handler->handle($request);
        }

        $response = (new ResponseFactory())->createResponse(302);
        return $response->withHeader('Location', '/admin/login');
    }
}
