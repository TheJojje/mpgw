<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Api;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ovriga las-endpoints for externa konsumenter: applikation, mallar, larm,
 * halsokoll. Allt read-only - skrivande sker enbart via adminvyn/pollern.
 */
final class MetaController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function health(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => ['status' => 'ok', 'time' => date('c')]]);
    }

    public function applications(Request $request, Response $response): Response
    {
        // En nyckel ar knuten till exakt en applikation - den far bara se
        // sig sjalv, annars lacker vi vilka andra applikationer/konsumenter
        // som finns.
        $apiKey = $request->getAttribute('api_key');
        $stmt = $this->db->prepare('SELECT id, name, is_active FROM applications WHERE id = ?');
        $stmt->execute([(int) $apiKey['application_id']]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function templates(Request $request, Response $response): Response
    {
        $apiKey = $request->getAttribute('api_key');
        $stmt = $this->db->prepare(
            'SELECT id, application_id, name, priority, is_active FROM templates WHERE application_id = ? ORDER BY priority'
        );
        $stmt->execute([(int) $apiKey['application_id']]);

        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function alerts(Request $request, Response $response): Response
    {
        $apiKey = $request->getAttribute('api_key');
        $stmt = $this->db->prepare(
            "SELECT a.id, a.type, a.severity, a.status, a.created_at, app.name AS application_name
             FROM alerts a LEFT JOIN applications app ON app.id = a.application_id
             WHERE a.status != 'resolved' AND a.application_id = ?
             ORDER BY a.created_at DESC LIMIT 100"
        );
        $stmt->execute([(int) $apiKey['application_id']]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
