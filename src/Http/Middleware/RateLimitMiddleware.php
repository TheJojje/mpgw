<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Mpgw\Service\AuditLogger;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enkel per-nyckel rate limiting med en fast 60-sekundersfonster-rakare
 * lagrad i databasen (MVP - rader per nyckel+fonster, ingen extern
 * cache/Redis-beroende). Tillrackligt for den volym gatewayen forvantas
 * hantera; kan bytas mot t.ex. APCu/Redis vid behov.
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $defaultLimitPerMinute,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $apiKey = $request->getAttribute('api_key');
        if ($apiKey === null) {
            return $handler->handle($request); // ingen nyckel - ApiKeyAuthMiddleware kor forst och stoppar redan da
        }

        // NULL (eller 0/negativt av misstag) faller tillbaka pa standardgransen,
        // sa en API-nyckel utan explicit override aldrig blir helt blockerad.
        $override = $apiKey['rate_limit_per_minute'] ?? null;
        $limit = ($override !== null && (int) $override > 0) ? (int) $override : $this->defaultLimitPerMinute;
        $windowStart = date('Y-m-d H:i:00');

        $this->db->prepare(
            'INSERT INTO api_key_rate_windows (api_key_id, window_start, request_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1'
        )->execute([$apiKey['id'], $windowStart]);

        $stmt = $this->db->prepare(
            'SELECT request_count FROM api_key_rate_windows WHERE api_key_id = ? AND window_start = ?'
        );
        $stmt->execute([$apiKey['id'], $windowStart]);
        $count = (int) $stmt->fetchColumn();

        if ($count > $limit) {
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            // object_type/object_id NULL - se ScopeMiddleware::process() for
            // varfor (undviker felaktig AuditDiffer-koppling mot nyckelns
            // andringshistorik).
            $this->audit->log(
                'api',
                $request->getMethod() . ' ' . $request->getUri()->getPath(),
                null,
                null,
                null,
                ($apiKey['consumer_name'] ?? 'Okänd nyckel') . ' nådde rate limit (' . $limit . '/min)',
                [
                    'ip' => $ip,
                    'status' => 429,
                    'api_key_id' => $apiKey['id'],
                    'key_prefix' => $apiKey['key_prefix'] ?? null,
                ],
                level: 'warning',
                source: 'api',
            );

            $response = (new ResponseFactory())->createResponse(429);
            $response->getBody()->write(json_encode([
                'error' => ['code' => 'rate_limited', 'message' => 'For manga anrop, forsok igen om en stund.'],
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Retry-After', '60');
        }

        return $handler->handle($request);
    }
}
