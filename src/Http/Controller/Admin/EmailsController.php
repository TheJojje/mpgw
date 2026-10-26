<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\DeletedEmailRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractedRecordRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractionRuleRepository;
use Mpgw\Infrastructure\Db\Repository\IngestLogRepository;
use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\HtmlToTextNormalizer;
use Mpgw\Service\RuleEngine;
use Mpgw\Service\TemplateMatcher;
use Mpgw\Support\FlashMessage;
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
        private readonly DeletedEmailRepository $deletedEmails,
        private readonly AuditLogger $audit,
        private readonly TemplateRepository $templates,
        private readonly TemplateMatcher $templateMatcher,
        private readonly HtmlToTextNormalizer $normalizer,
        private readonly ExtractedRecordRepository $records,
        private readonly IngestLogRepository $ingestLog,
    ) {
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $email = $this->rawEmails->findByUuid($args['uuid']);
        if ($email === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $userId = !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
        $this->deletedEmails->deleteEmailWithTombstone($email, $userId);

        $this->audit->log('emails', 'deleted', $userId, 'raw_email', (int) $email['id'], 'Mail raderades', [
            'uuid' => $email['uuid'],
            'mailbox_id' => $email['mailbox_id'],
            'subject' => $email['subject'],
        ]);

        FlashMessage::success('Mailet raderades och kommer inte importeras igen.');

        return $response->withHeader('Location', '/admin/emails')->withStatus(302);
    }

    /**
     * Kor om mall-matchning + extraction_rules mot mailets sparade text och
     * ersatter det lagrade resultatet. Gor det mojligt att verifiera att en
     * regelandring ger 100 % traff pa ett delvis matchat mail.
     */
    public function reparse(Request $request, Response $response, array $args): Response
    {
        $email = $this->rawEmails->findByUuid($args['uuid']);
        if ($email === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $back = $response->withHeader('Location', '/admin/emails/' . $email['uuid'])->withStatus(302);

        $mailboxStmt = $this->db->prepare('SELECT application_id FROM mailboxes WHERE id = ?');
        $mailboxStmt->execute([$email['mailbox_id']]);
        $applicationId = $mailboxStmt->fetchColumn();
        if ($applicationId === false || $email['normalized_text'] === null) {
            FlashMessage::error('Mailet saknar mailbox eller text och kan inte parsas om.');
            return $back;
        }

        $template = $this->templateMatcher->match(
            $this->templates->activeForApplication((int) $applicationId),
            (string) $email['from_address'],
            (string) $email['subject'],
        );
        if ($template === null) {
            FlashMessage::error('Ingen aktiv mall matchar det här mailet, så det finns inget att parsa om.');
            return $back;
        }

        $rules = $this->rules->activeForTemplate((int) $template['id']);
        $result = $this->ruleEngine->run(
            $rules,
            $email['normalized_text'],
            $this->normalizer->normalize((string) $email['subject'], false),
        );
        $this->records->replaceResult((int) $email['id'], (int) $template['id'], $result);

        $userId = !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
        $this->ingestLog->log(
            $email['mailbox_id'] !== null ? (int) $email['mailbox_id'] : null,
            (int) $email['id'],
            'rule_engine_ran',
            $result->status . ' (omparsning)',
        );
        $this->audit->log('emails', 'reparsed', $userId, 'raw_email', (int) $email['id'], 'Mail parsades om', [
            'uuid' => $email['uuid'],
            'template' => $template['name'],
            'status' => $result->status,
            'match_rate' => $result->matchRate,
        ]);

        $msg = sprintf('Omparsat med mallen "%s": träffgrad %d %%.', $template['name'], (int) round($result->matchRate * 100));
        if ($result->status === 'success') {
            FlashMessage::success($msg);
        } else {
            FlashMessage::error($msg . ' Saknas: ' . implode(', ', array_column(array_filter($result->fields, static fn (array $f): bool => !$f['matched']), 'field_name')));
        }

        return $back;
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
                $engineResult = $this->ruleEngine->run(
                    $rules,
                    $email['normalized_text'],
                    $this->normalizer->normalize((string) $email['subject'], false),
                );
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
