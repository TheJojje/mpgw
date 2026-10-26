<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Api;

use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RecordsController
{
    public function __construct(
        private readonly PDO $db,
        private readonly RawEmailRepository $rawEmails,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        // En nyckel ar knuten till exakt en applikation - begransa alltid
        // resultatet till den, aven om anroparen inte skickar nagot filter.
        $apiKey = $request->getAttribute('api_key');
        $where = ['t.application_id = ?'];
        $args = [(int) $apiKey['application_id']];
        if (!empty($params['status'])) {
            $where[] = 'er.status = ?';
            $args[] = $params['status'];
        }
        if (!empty($params['template_id'])) {
            $where[] = 'er.template_id = ?';
            $args[] = (int) $params['template_id'];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT er.*, t.name AS template_name, app.name AS application_name, re.received_at
             FROM extracted_records er
             LEFT JOIN templates t ON t.id = er.template_id
             LEFT JOIN applications app ON app.id = t.application_id
             LEFT JOIN raw_emails re ON re.id = er.raw_email_id
             {$whereSql}
             ORDER BY er.processed_at DESC
             LIMIT ? OFFSET ?"
        );
        $bindArgs = $args;
        $bindArgs[] = $perPage;
        $bindArgs[] = $offset;
        foreach ($bindArgs as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $records = $stmt->fetchAll();

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM extracted_records er
             LEFT JOIN templates t ON t.id = er.template_id
             {$whereSql}"
        );
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $data = array_map(fn (array $r) => $this->formatRecord($r), $records);

        return $this->json($response, [
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $apiKey = $request->getAttribute('api_key');
        $stmt = $this->db->prepare(
            'SELECT er.*, t.name AS template_name, app.name AS application_name, re.received_at
             FROM extracted_records er
             LEFT JOIN templates t ON t.id = er.template_id
             LEFT JOIN applications app ON app.id = t.application_id
             LEFT JOIN raw_emails re ON re.id = er.raw_email_id
             WHERE er.uuid = ? AND t.application_id = ?'
        );
        $stmt->execute([$args['uuid'], (int) $apiKey['application_id']]);
        $record = $stmt->fetch();

        if ($record === false) {
            return $this->json($response->withStatus(404), ['error' => ['code' => 'not_found', 'message' => 'Record hittades inte.']]);
        }

        return $this->json($response, ['data' => $this->formatRecord($record, includeFields: true)]);
    }

    public function raw(Request $request, Response $response, array $args): Response
    {
        $apiKey = $request->getAttribute('api_key');
        $scopes = json_decode((string) ($apiKey['scopes'] ?? '[]'), true) ?: [];
        if (!in_array('records:read:raw', $scopes, true)) {
            return $this->json($response->withStatus(403), [
                'error' => ['code' => 'insufficient_scope', 'message' => 'Kraver scope: records:read:raw'],
            ]);
        }

        $rawEmail = $this->rawEmails->findRawForRecord($args['uuid'], (int) $apiKey['application_id']);

        if ($rawEmail === null) {
            return $this->json($response->withStatus(404), ['error' => ['code' => 'not_found', 'message' => 'Record hittades inte.']]);
        }

        return $this->json($response, [
            'data' => [
                'from' => $rawEmail['from_address'],
                'subject' => $rawEmail['subject'],
                'received_at' => $rawEmail['received_at'],
                'raw_headers' => $rawEmail['raw_headers'],
                'raw_body' => $rawEmail['raw_body'],
            ],
        ]);
    }

    private function formatRecord(array $record, bool $includeFields = false): array
    {
        $result = [
            'id' => $record['uuid'],
            'application' => $record['application_name'],
            'template' => $record['template_name'],
            'status' => $record['status'],
            'match_rate' => (float) $record['match_rate'],
            'received_at' => $record['received_at'],
            'processed_at' => $record['processed_at'],
        ];

        if ($includeFields) {
            $stmt = $this->db->prepare('SELECT field_name, field_value, matched FROM extracted_fields WHERE extracted_record_id = ?');
            $stmt->execute([$record['id']]);
            $fields = [];
            foreach ($stmt->fetchAll() as $field) {
                $fields[$field['field_name']] = $field['matched'] ? $field['field_value'] : null;
            }
            $result['fields'] = $fields;
        }

        return $result;
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
