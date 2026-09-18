<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Mpgw\Service\AuditLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Kontrollerar att den autentiserade API-nyckeln (satt av ApiKeyAuthMiddleware
 * pa requesten som attributet "api_key") har det scope som routen kraver.
 * Registreras per route via factory-funktionen scope_middleware() i
 * config/routes.php (istallet for new ScopeMiddleware(...) direkt) sa
 * AuditLogger kan injiceras fran containern.
 *
 * Detta ar ocksa den enda punkten dar ett API-anrops SLUTLIGA utfall (200
 * eller nagot senare fel fran controllern) loggas for lyckade
 * scope-kontroller - eftersom PSR-7-requests ar immutable kan ingen
 * middleware UTANFOR denna (ApiKeyAuthMiddleware/RateLimitMiddleware) se
 * "api_key"-attributet efter att $handler->handle() korts harifran, sa
 * loggningen maste ske precis har, narmast controllern.
 *
 * object_type/object_id lamnas medvetet NULL har (inte satta till
 * 'api_key'/nyckelns id) - AuditDiffer::diffForEvent() bygger en falt-diff
 * mot NARMAST FOREGAENDE log_events-rad for samma (object_type, object_id),
 * vilket ar ratt for en entitets andringshistorik (t.ex. api_keys.created)
 * men skulle felaktigt tolka varje nytt API-anrop mot samma nyckel som en
 * "andring" av den och rendera en missvisande diff i logs/show.twig.
 * api_key_id finns fortfarande sokbart i content_params.
 */
final class ScopeMiddleware implements Middleware
{
    public function __construct(
        private readonly string $requiredScope,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $apiKey = $request->getAttribute('api_key');
        $scopes = $apiKey !== null ? (json_decode((string) $apiKey['scopes'], true) ?: []) : [];

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        if (!in_array($this->requiredScope, $scopes, true)) {
            $this->audit->log(
                'api',
                "{$method} {$path}",
                null,
                null,
                null,
                ($apiKey['consumer_name'] ?? 'Okänd nyckel') . " saknar scope {$this->requiredScope}",
                [
                    'ip' => $ip,
                    'status' => 403,
                    'api_key_id' => $apiKey['id'] ?? null,
                    'key_prefix' => $apiKey['key_prefix'] ?? null,
                    'required_scope' => $this->requiredScope,
                ],
                level: 'warning',
                source: 'api',
            );

            $response = (new ResponseFactory())->createResponse(403);
            $response->getBody()->write(json_encode([
                'error' => ['code' => 'insufficient_scope', 'message' => "Kraver scope: {$this->requiredScope}"],
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response = $handler->handle($request);

        $this->audit->log(
            'api',
            "{$method} {$path}",
            null,
            null,
            null,
            ($apiKey['consumer_name'] ?? 'Okänd nyckel') . " anropade {$method} {$path}",
            [
                'ip' => $ip,
                'status' => $response->getStatusCode(),
                'api_key_id' => $apiKey['id'] ?? null,
                'key_prefix' => $apiKey['key_prefix'] ?? null,
                'application_id' => $apiKey['application_id'] ?? null,
            ],
            source: 'api',
        );

        return $response;
    }
}
