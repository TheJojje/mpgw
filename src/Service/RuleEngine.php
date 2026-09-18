<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Kor alla extraction_rules i en mall mot normaliserad mailtext. Varje
 * regel valjer sjalv vilken textkalla den kors mot via sitt 'source'-falt
 * (body/subject) - se extraction_rules.source. Anvands bade for "5/6
 * regler gav traff"-sammanfattningen i mallbyggarens live-preview och for
 * faktisk produktionsingest via RuleEngineResult.
 */
final class RuleEngine
{
    public function __construct(
        private readonly RegexRuleTester $tester,
    ) {
    }

    /**
     * @param array<int,array{id:int,field_name:string,pattern:string,is_required:bool,source?:string}> $rules
     */
    public function run(array $rules, string $normalizedBody, string $normalizedSubject = ''): RuleEngineResult
    {
        $fields = [];
        $matchedCount = 0;
        $missingRequired = [];

        foreach ($rules as $rule) {
            $text = ($rule['source'] ?? 'body') === 'subject' ? $normalizedSubject : $normalizedBody;
            $result = $this->tester->test($rule['pattern'], $text);

            $fields[] = [
                'field_name' => $rule['field_name'],
                'rule_id' => $rule['id'],
                'matched' => $result->matched,
                'value' => $result->value,
                'offset' => $result->offset,
                'length' => $result->length,
                'error' => $result->error,
            ];

            if ($result->matched) {
                $matchedCount++;
            } elseif (!empty($rule['is_required'])) {
                $missingRequired[] = $rule['field_name'];
            }
        }

        $total = count($rules);
        $matchRate = $total > 0 ? round($matchedCount / $total, 2) : 0.0;

        $status = match (true) {
            $total === 0 => 'failed',
            $matchedCount === $total => 'success',
            $matchedCount > 0 => 'partial',
            default => 'failed',
        };

        if ($missingRequired !== [] && $status !== 'failed') {
            $status = 'partial';
        }

        return new RuleEngineResult($status, $matchRate, $fields, $missingRequired);
    }
}
