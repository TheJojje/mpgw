<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Mpgw\Service\AuthorizationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Kontrollerar att den inloggade adminanvandaren har den rattighet routen
 * kraver (se AuthorizationService::can). Registreras per route med
 * ->add(new PermissionMiddleware($authz, 'users.manage')), samma monster
 * som ScopeMiddleware anvands for det externa API:et. Laggs som ett extra
 * lager UTOVER AdminAuthMiddleware, som fortsatter vara den generella
 * "ar inloggad"-kontrollen.
 */
final class PermissionMiddleware implements Middleware
{
    public function __construct(
        private readonly AuthorizationService $authz,
        private readonly string $permission,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($this->authz->can($this->permission)) {
            return $handler->handle($request);
        }

        $response = (new ResponseFactory())->createResponse(403);
        $response->getBody()->write('Åtkomst nekad. Din roll saknar rättigheten "' . $this->permission . '".');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
