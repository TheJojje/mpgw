<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use Mpgw\Service\RegexRuleTester;
use Mpgw\Service\RuleEngine;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RuleEngine(new RegexRuleTester());
    }

    public function testAllRulesMatchGivesSuccessStatus(): void
    {
        $text = "Orderdatum: 2026-09-10\nReferens: AB123";
        $rules = [
            ['id' => 1, 'field_name' => 'order_date', 'pattern' => '/Orderdatum:\s*(?<value>\d{4}-\d{2}-\d{2})/', 'is_required' => true],
            ['id' => 2, 'field_name' => 'reference', 'pattern' => '/Referens:\s*(?<value>[A-Za-z]+\d+)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $text);

        self::assertSame('success', $result->status);
        self::assertSame(1.0, $result->matchRate);
        self::assertSame([], $result->missingRequiredFields);
    }

    public function testMissingRequiredFieldGivesPartialStatus(): void
    {
        $text = "Orderdatum: 2026-09-10";
        $rules = [
            ['id' => 1, 'field_name' => 'order_date', 'pattern' => '/Orderdatum:\s*(?<value>\d{4}-\d{2}-\d{2})/', 'is_required' => true],
            ['id' => 2, 'field_name' => 'reference', 'pattern' => '/Referens:\s*(?<value>[A-Za-z]+\d+)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $text);

        self::assertSame('partial', $result->status);
        self::assertSame(0.5, $result->matchRate);
        self::assertSame(['reference'], $result->missingRequiredFields);
    }

    public function testNoRulesMatchGivesFailedStatus(): void
    {
        $text = "Helt annan text utan de falt vi letar efter.";
        $rules = [
            ['id' => 1, 'field_name' => 'order_date', 'pattern' => '/Orderdatum:\s*(?<value>\d{4}-\d{2}-\d{2})/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $text);

        self::assertSame('failed', $result->status);
        self::assertSame(0.0, $result->matchRate);
    }

    public function testOptionalFieldMissingDoesNotBlockSuccess(): void
    {
        $text = "Orderdatum: 2026-09-10";
        $rules = [
            ['id' => 1, 'field_name' => 'order_date', 'pattern' => '/Orderdatum:\s*(?<value>\d{4}-\d{2}-\d{2})/', 'is_required' => true],
            ['id' => 2, 'field_name' => 'optional_note', 'pattern' => '/Notering:\s*(?<value>.+)/', 'is_required' => false],
        ];

        $result = $this->engine->run($rules, $text);

        // Ett optional-falt som saknas paverkar inte status (bara required gor det),
        // men match_rate ateraspeglar fortfarande att inte alla regler traffade.
        self::assertSame('partial', $result->status);
        self::assertSame([], $result->missingRequiredFields);
    }

    public function testRuleWithoutSourceDefaultsToBody(): void
    {
        // Bakatkompatibilitet: regler utan source-nyckel (t.ex. skapade fore
        // denna funktion, eller extraction_rules.source's DEFAULT 'body')
        // ska bete sig exakt som innan - kora mot brodtexten.
        $body = 'Referens: AB123';
        $subject = 'Referens: XY999';
        $rules = [
            ['id' => 1, 'field_name' => 'reference', 'pattern' => '/Referens:\s*(?<value>[A-Za-z]+\d+)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $body, $subject);

        self::assertSame('AB123', $result->fields[0]['value']);
    }

    public function testRuleWithSourceSubjectMatchesAgainstSubjectNotBody(): void
    {
        $body = 'Ett vanligt meddelande utan nagon typangivelse.';
        $subject = 'En ny bokning';
        $rules = [
            ['id' => 1, 'field_name' => 'event_type', 'source' => 'subject', 'pattern' => '/(?<value>[Bb]okning|[Aa]vbokning)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $body, $subject);

        self::assertSame('success', $result->status);
        self::assertSame('bokning', $result->fields[0]['value']);
    }

    public function testRuleWithSourceSubjectDoesNotMatchAgainstBody(): void
    {
        $body = 'En ny bokning har genomforts for lagenhet 12.';
        $subject = 'Kvitto';
        $rules = [
            ['id' => 1, 'field_name' => 'event_type', 'source' => 'subject', 'pattern' => '/(?<value>[Aa]vbokning)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $body, $subject);

        // "avbokning" finns bara i brodtexten (indirekt, "bokning" ar
        // substring) - regeln ska ENDAST kora mot amnesraden ("Kvitto"),
        // sa den far inte trafa av misstag mot brodtexten.
        self::assertFalse($result->fields[0]['matched']);
    }

    public function testMixedSourcesInSameTemplateRunIndependently(): void
    {
        $body = 'Lagenhet: 12';
        $subject = 'En ny bokning';
        $rules = [
            ['id' => 1, 'field_name' => 'event_type', 'source' => 'subject', 'pattern' => '/(?<value>[Bb]okning|[Aa]vbokning)/', 'is_required' => true],
            ['id' => 2, 'field_name' => 'apartment_number', 'source' => 'body', 'pattern' => '/Lagenhet:\s*(?<value>\d+)/', 'is_required' => true],
        ];

        $result = $this->engine->run($rules, $body, $subject);

        self::assertSame('success', $result->status);
        self::assertSame('bokning', $result->fields[0]['value']);
        self::assertSame('12', $result->fields[1]['value']);
    }
}
