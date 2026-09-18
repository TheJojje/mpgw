<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractionRuleRepository;
use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use Mpgw\Service\RuleEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EmailsController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly ExtractionRuleRepository $rules,
        private readonly RuleEngine $ruleEngine,
        private readonly ApplicationRepository $applications,
        private readonly RawEmailRepository $rawEmails,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['q'] ?? ''));
        $status = in_array($params['status'] ?? null, ['success', 'partial', 'failed'], true) ? $params['status'] : null;
        $applicationId = ($params['application'] ?? '') !== '' ? (int) $params['application'] : null;

        $where = [];
        $args = [];
        if ($search !== '') {
            $where[] = '(re.from_address LIKE ? OR re.subject LIKE ?)';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }
        if ($status !== null) {
            $where[] = 'er.status = ?';
            $args[] = $status;
        }
        if ($applicationId !== null) {
            $where[] = 'mb.application_id = ?';
            $args[] = $applicationId;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            'SELECT re.*, er.status
             FROM raw_emails re
             LEFT JOIN extracted_records er ON er.raw_email_id = re.id
             LEFT JOIN mailboxes mb ON mb.id = re.mailbox_id'
            . $whereSql .
            ' ORDER BY re.fetched_at DESC LIMIT 100'
        );
        $stmt->execute($args);

        return $this->view->render($request, $response, 'emails/index.twig', [
            'emails' => $stmt->fetchAll(),
            'search' => $search,
            'status_filter' => $status,
            'application_filter' => $applicationId,
            'applications' => $this->applications->allActive(),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $email = $this->rawEmails->findByUuid($args['uuid']);
        if ($email === null) {
            return $response->withStatus(404);
        }

        $recordStmt = $this->db->prepare('SELECT * FROM extracted_records WHERE raw_email_id = ?');
        $recordStmt->execute([$email['id']]);
        $record = $recordStmt->fetch() ?: null;

        $fields = [];
        if ($record !== null) {
            $fieldStmt = $this->db->prepare('SELECT * FROM extracted_fields WHERE extracted_record_id = ?');
            $fieldStmt->execute([$record['id']]);
            $fields = $fieldStmt->fetchAll();
        }

        // Samma färgkopplingskomponent som mallbyggaren (rule-till-text) återanvänds
        // här: extraherat fält <-> position i råtexten. extracted_fields sparar inget
        // charoffset, så vi kör om reglerna mot normalized_text på det här mailet —
        // exakt samma RuleEngine som produktionsingest och mallbyggaren använder.
        $fieldMatches = [];
        if ($record !== null && $record['template_id'] !== null && $email['normalized_text'] !== null) {
            $rules = $this->rules->activeForTemplate((int) $record['template_id']);
            if ($rules !== []) {
                $engineResult = $this->ruleEngine->run($rules, $email['normalized_text']);
                $fieldMatches = $this->charOffsetFieldsByName($engineResult->fields, $email['normalized_text']);
            }
        }

        return $this->view->render($request, $response, 'emails/show.twig', [
            'email' => $email,
            'record' => $record,
            'fields' => $fields,
            'field_matches' => $fieldMatches,
        ]);
    }

    /**
     * Se TemplatesController::charOffsetFieldsByRuleId() för motivering — samma
     * byte->char-offset-konvertering, men nyckling på field_name eftersom
     * emails/show.twig listar extracted_fields (som saknar ett garanterat rule_id).
     *
     * @param array<int,array{field_name:string,rule_id:int,matched:bool,value:?string,offset:?int,length:?int,error:?string}> $fields
     * @return array<string,array{matched:bool,value:?string,char_start:?int,char_end:?int}>
     */
    private function charOffsetFieldsByName(array $fields, string $text): array
    {
        $byFieldName = [];
        foreach ($fields as $field) {
            $charStart = null;
            $charEnd = null;
            if ($field['matched'] && $field['offset'] !== null) {
                $charStart = mb_strlen(substr($text, 0, $field['offset']), 'UTF-8');
                $charEnd = $charStart + mb_strlen((string) $field['value'], 'UTF-8');
            }

            $byFieldName[$field['field_name']] = [
                'matched' => $field['matched'],
                'value' => $field['value'],
                'char_start' => $charStart,
                'char_end' => $charEnd,
            ];
        }

        return $byFieldName;
    }
}
