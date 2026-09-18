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
 * Autentiserar externa API-anrop via X-API-Key-header. Nyckeln hashas
 * och slas upp mot api_keys.key_hash - full nyckel lagras aldrig i
 * klartext i databasen. Den matchade nyckelns rad lags pa requesten
 * som attributet "api_key" sa senare middleware (scope-koll,
 * rate-limit) och controllern kan lasa den.
 *
 * Loggar avvisade forsok (401) direkt har - inte i en separat "logga i
 * efterhand"-middleware langre bort i kedjan, eftersom PSR-7-requests ar
 * immutable: en middleware som ligger UTANFOR denna kan aldrig se
 * withAttribute()-anropet nedan (den ser bara sin egen, omuterade
 * $request-variabel), sa den enda platsen som sakert kanner till bade
 * "vilken nyckel" och "lyckades det" ar precis har detta avgors.
 */
final class ApiKeyAuthMiddleware implements Middleware
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        $key = $request->getHeaderLine('X-API-Key');
        if ($key === '') {
            return $this->unauthorized($ip, $method, $path, 'Header X-API-Key saknas.');
        }

        $hash = hash('sha256', $key);
        $stmt = $this->db->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1');
        $stmt->execute([$hash]);
        $apiKey = $stmt->fetch();

        if ($apiKey === false) {
            return $this->unauthorized($ip, $method, $path, 'Ogiltig eller aterkallad API-nyckel.');
        }

        if (!empty($apiKey['expires_at']) && strtotime($apiKey['expires_at']) < time()) {
            return $this->unauthorized($ip, $method, $path, 'API-nyckeln har gatt ut.', $apiKey);
        }

        $updateStmt = $this->db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?');
        $updateStmt->execute([$apiKey['id']]);

        $request = $request->withAttribute('api_key', $apiKey);

        return $handler->handle($request);
    }

    /** @param array<string,mixed>|null $apiKey */
    private function unauthorized(?string $ip, string $method, string $path, string $message, ?array $apiKey = null): Response
    {
        // object_type/object_id lamnas NULL - se ScopeMiddleware::process()
        // for varfor (annars felaktig AuditDiffer-koppling mot nyckelns
        // egen andringshistorik). api_key_id sokbart i content_params.
        $this->audit->log(
            'api',
            "{$method} {$path}",
            null,
            null,
            null,
            "Avvisat API-anrop: {$message}",
            [
                'ip' => $ip,
                'status' => 401,
                'api_key_id' => $apiKey['id'] ?? null,
                'key_prefix' => $apiKey['key_prefix'] ?? null,
            ],
            level: 'warning',
            source: 'api',
        );

        $response = (new ResponseFactory())->createResponse(401);
        $response->getBody()->write(json_encode(['error' => ['code' => 'unauthorized', 'message' => $message]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
