<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractionRuleRepository;
use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\HtmlToTextNormalizer;
use Mpgw\Service\RegexRuleTester;
use Mpgw\Service\RegexSuggester;
use Mpgw\Service\RuleEngine;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TemplatesController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly TemplateRepository $templates,
        private readonly ApplicationRepository $applications,
        private readonly ExtractionRuleRepository $rules,
        private readonly RawEmailRepository $rawEmails,
        private readonly RegexSuggester $suggester,
        private readonly RegexRuleTester $tester,
        private readonly HtmlToTextNormalizer $normalizer,
        private readonly RuleEngine $ruleEngine,
        private readonly AuditLogger $audit,
    ) {
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return $response->withStatus(404);
        }

        $application = $this->applications->find((int) $template['application_id']);
        $rules = $this->rules->activeForTemplate($templateId);
        $recentEmails = $this->rawEmails->recent(20);

        $queryParams = $request->getQueryParams();
        $selectedRawEmailId = isset($queryParams['raw_email_id']) ? (int) $queryParams['raw_email_id'] : null;
        $normalizedText = null;
        $normalizedSubject = null;

        if ($selectedRawEmailId !== null) {
            $rawEmail = $this->rawEmails->find($selectedRawEmailId);
            $normalizedText = $rawEmail['normalized_text'] ?? null;
            $normalizedSubject = $this->normalizer->normalize($rawEmail['subject'] ?? '', false);
        }

        // Kör alla regler samtidigt mot den inlästa texten (samma RuleEngine som
        // produktionsingest använder) så vi kan visa VAR i texten varje sparad
        // regel träffar — inte bara ett facit-resultat i listan som tidigare.
        // Regler med source=subject matchas mot amnesraden, ovriga mot
        // brodtexten - se charOffsetFieldsByRuleId() som kanner till bada.
        $ruleMatches = [];
        if ($normalizedText !== null && $rules !== []) {
            $engineResult = $this->ruleEngine->run($rules, $normalizedText, $normalizedSubject ?? '');
            $ruleMatches = $this->charOffsetFieldsByRuleId($engineResult->fields, $rules, $normalizedText, $normalizedSubject ?? '');
        }

        return $this->view->render($request, $response, 'templates/edit.twig', [
            'template' => $template,
            'application' => $application,
            'rules' => $rules,
            'recent_emails' => $recentEmails,
            'selected_raw_email_id' => $selectedRawEmailId,
            'normalized_text' => $normalizedText,
            'normalized_subject' => $normalizedSubject,
            'rule_matches' => $ruleMatches,
        ]);
    }

    /**
     * RuleEngine/RegexRuleTester ger byte-offset (PREG_OFFSET_CAPTURE), men
     * Twig/JS behöver teckenoffset för att kunna splitta UTF-8-texten korrekt
     * (annars hamnar <mark>-taggarna fel så fort texten innehåller å/ä/ö).
     * Varje falt maste rakna offset mot RATT kalltext (subject eller body)
     * beroende pa dess regels source-falt - annars hamnar markeringen i fel
     * ruta eller pa fel position.
     *
     * @param array<int,array{field_name:string,rule_id:int,matched:bool,value:?string,offset:?int,length:?int,error:?string}> $fields
     * @param array<int,array{id:int,source?:string}> $rules
     * @return array<int,array{rule_id:int,matched:bool,value:?string,char_start:?int,char_end:?int}>
     */
    private function charOffsetFieldsByRuleId(array $fields, array $rules, string $bodyText, string $subjectText): array
    {
        $sourceByRuleId = [];
        foreach ($rules as $rule) {
            $sourceByRuleId[$rule['id']] = $rule['source'] ?? 'body';
        }

        $byRuleId = [];
        foreach ($fields as $field) {
            $text = ($sourceByRuleId[$field['rule_id']] ?? 'body') === 'subject' ? $subjectText : $bodyText;

            $charStart = null;
            $charEnd = null;
            if ($field['matched'] && $field['offset'] !== null && $field['length'] !== null) {
                $charStart = mb_strlen(substr($text, 0, $field['offset']), 'UTF-8');
                $charEnd = $charStart + mb_strlen((string) $field['value'], 'UTF-8');
            }

            $byRuleId[$field['rule_id']] = [
                'rule_id' => $field['rule_id'],
                'matched' => $field['matched'],
                'value' => $field['value'],
                'char_start' => $charStart,
                'char_end' => $charEnd,
            ];
        }

        return $byRuleId;
    }

    /** Uppdaterar mallens egna falt (namn/fran-monster/prioritet) - inte extraktionsreglerna. */
    public function updateFields(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $application = $this->applications->find((int) $template['application_id']);
            $rules = $this->rules->activeForTemplate($templateId);

            return $this->view->render($request, $response->withStatus(422), 'templates/edit.twig', [
                'template' => $template,
                'application' => $application,
                'rules' => $rules,
                'recent_emails' => $this->rawEmails->recent(20),
                'selected_raw_email_id' => null,
                'normalized_text' => null,
                'normalized_subject' => null,
                'rule_matches' => [],
                'fields_error' => 'Namn krävs.',
            ]);
        }

        $this->templates->update(
            $templateId,
            $name,
            $data['from_pattern'] ?: null,
            (string) ($data['from_match_type'] ?: 'contains'),
            $data['subject_regex'] ?: null,
            (int) ($data['priority'] ?: 100),
        );

        $this->audit->log('templates', 'updated', $this->currentUserId(), 'template', $templateId, "Mall {$name} uppdaterades", [
            'name' => $name,
            'from_pattern' => $data['from_pattern'] ?: null,
            'from_match_type' => (string) ($data['from_match_type'] ?: 'contains'),
            'subject_regex' => $data['subject_regex'] ?: null,
            'priority' => (int) ($data['priority'] ?: 100),
        ]);

        FlashMessage::success("Mallen {$name} uppdaterades.");

        return $response->withHeader('Location', '/admin/templates/' . $templateId)->withStatus(302);
    }

    /** AJAX: normaliserar inklistrad text (utan att spara nagot) sa den kan visas/markeras i mallbyggaren. */
    public function normalizePreview(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $rawText = (string) ($data['raw_text'] ?? '');
        $isHtml = str_contains($rawText, '<html') || str_contains($rawText, '<body') || str_contains($rawText, '<div');

        $normalized = $this->normalizer->normalize($rawText, $isHtml);

        return $this->json($response, ['normalized_text' => $normalized]);
    }

    /** AJAX: foreslar regex-monster for en markering i texten. */
    public function suggestPattern(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $fullText = (string) ($data['full_text'] ?? '');
        $start = (int) ($data['selection_start'] ?? 0);
        $end = (int) ($data['selection_end'] ?? 0);

        if ($fullText === '' || $end <= $start) {
            return $this->json($response, ['suggestions' => []]);
        }

        $suggestions = $this->suggester->suggest($fullText, $start, $end);

        return $this->json($response, [
            'suggestions' => array_map(static fn ($s) => $s->toArray(), $suggestions),
        ]);
    }

    /** AJAX: kor ett monster mot en text live, samma logik som produktionsingest anvander. */
    public function testPattern(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $pattern = (string) ($data['pattern'] ?? '');
        $text = (string) ($data['text'] ?? '');

        $result = $this->tester->test($pattern, $text);

        return $this->json($response, $result->toArray());
    }

    /** Sparar en ny extraction_rule for mallen. Postas som JSON fran mallbyggarens JS. */
    public function createRule(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $this->json($response->withStatus(400), ['error' => 'Ogiltig session (CSRF), ladda om sidan.']);
        }

        $fieldName = trim((string) ($data['field_name'] ?? ''));
        $pattern = (string) ($data['pattern'] ?? '');

        if ($fieldName === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            return $this->json($response->withStatus(422), ['error' => 'Fältnamn måste vara gemener/siffror/understreck och börja med en bokstav.']);
        }
        if ($pattern === '') {
            return $this->json($response->withStatus(422), ['error' => 'Mönster krävs.']);
        }

        // Validera att monstret ar ett giltigt regex innan det sparas.
        if (@preg_match($pattern, '') === false) {
            return $this->json($response->withStatus(422), ['error' => 'Ogiltigt regex-mönster.']);
        }

        $source = ($data['source'] ?? '') === 'subject' ? 'subject' : 'body';

        $ruleId = $this->rules->create(
            $templateId,
            $fieldName,
            $data['field_label'] ?: null,
            (string) ($data['data_type'] ?: 'string'),
            $pattern,
            (bool) ($data['is_required'] ?? false),
            $data['sample_source_snippet'] ?? null,
            null,
            $source,
        );

        $this->audit->log('templates', 'rule_created', $this->currentUserId(), 'extraction_rule', $ruleId, "Regel {$fieldName} skapades", [
            'template_id' => $templateId,
            'field_name' => $fieldName,
            'pattern' => $pattern,
            'is_required' => (bool) ($data['is_required'] ?? false),
        ]);

        return $this->json($response, ['id' => $ruleId, 'field_name' => $fieldName]);
    }

    /** Uppdaterar en befintlig regel in-place. Postas som JSON PUT fran mallbyggarens JS. */
    public function updateRule(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $ruleId = (int) $args['ruleId'];
        $template = $this->templates->find($templateId);
        if ($template === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $this->json($response->withStatus(400), ['error' => 'Ogiltig session (CSRF), ladda om sidan.']);
        }

        $fieldName = trim((string) ($data['field_name'] ?? ''));
        $pattern = (string) ($data['pattern'] ?? '');

        if ($fieldName === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            return $this->json($response->withStatus(422), ['error' => 'Fältnamn måste vara gemener/siffror/understreck och börja med en bokstav.']);
        }
        if ($pattern === '') {
            return $this->json($response->withStatus(422), ['error' => 'Mönster krävs.']);
        }

        if (@preg_match($pattern, '') === false) {
            return $this->json($response->withStatus(422), ['error' => 'Ogiltigt regex-mönster.']);
        }

        $source = ($data['source'] ?? '') === 'subject' ? 'subject' : 'body';

        $updated = $this->rules->update(
            $ruleId,
            $templateId,
            $fieldName,
            $data['field_label'] ?: null,
            (string) ($data['data_type'] ?: 'string'),
            $pattern,
            (bool) ($data['is_required'] ?? false),
            $source,
        );

        if (!$updated) {
            return $this->json($response->withStatus(404), ['error' => 'Regeln hittades inte.']);
        }

        $this->audit->log('templates', 'rule_updated', $this->currentUserId(), 'extraction_rule', $ruleId, "Regel {$fieldName} uppdaterades", [
            'template_id' => $templateId,
            'field_name' => $fieldName,
            'pattern' => $pattern,
            'is_required' => (bool) ($data['is_required'] ?? false),
        ]);

        return $this->json($response, ['id' => $ruleId, 'field_name' => $fieldName]);
    }

    /** Inaktiverar (soft-delete) en regel. Postas som JSON DELETE fran mallbyggarens JS. */
    public function deleteRule(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $ruleId = (int) $args['ruleId'];

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $this->json($response->withStatus(400), ['error' => 'Ogiltig session (CSRF), ladda om sidan.']);
        }

        $removed = $this->rules->deactivate($ruleId, $templateId);
        if (!$removed) {
            return $this->json($response->withStatus(404), ['error' => 'Regeln hittades inte.']);
        }

        $this->audit->log('templates', 'rule_deleted', $this->currentUserId(), 'extraction_rule', $ruleId, 'Regel raderades', [
            'template_id' => $templateId,
        ]);

        return $this->json($response, ['deleted' => true]);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }
}
