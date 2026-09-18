<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use Mpgw\Service\RegexRuleTester;
use Mpgw\Service\RegexSuggester;
use PHPUnit\Framework\TestCase;

final class RegexSuggesterTest extends TestCase
{
    private RegexSuggester $suggester;
    private RegexRuleTester $tester;

    protected function setUp(): void
    {
        $this->suggester = new RegexSuggester();
        $this->tester = new RegexRuleTester();
    }

    public function testSuggestsIsoDatePattern(): void
    {
        $text = "Orderdatum: 2026-09-10, Referens: AB123";
        $start = mb_strpos($text, '2026-09-10');
        $end = $start + mb_strlen('2026-09-10');

        $suggestions = $this->suggester->suggest($text, $start, $end);

        self::assertNotEmpty($suggestions);
        self::assertStringContainsString('Datum', $suggestions[0]->label);

        // Forslaget ska faktiskt matcha och extrahera samma varde ur originaltexten.
        $result = $this->tester->test($suggestions[0]->pattern, $text);
        self::assertTrue($result->matched);
        self::assertSame('2026-09-10', $result->value);
    }

    public function testGeneralizedDatePatternMatchesOtherDates(): void
    {
        $text = "Orderdatum: 2026-09-10, Referens: AB123";
        $start = mb_strpos($text, '2026-09-10');
        $end = $start + mb_strlen('2026-09-10');

        $suggestions = $this->suggester->suggest($text, $start, $end);
        $generalized = $suggestions[0];

        // Samma monster ska ocksa traffa ett FRAMTIDA mail med annat datum.
        $futureText = "Orderdatum: 2027-01-05, Referens: CD999";
        $result = $this->tester->test($generalized->pattern, $futureText);

        self::assertTrue($result->matched);
        self::assertSame('2027-01-05', $result->value);
    }

    public function testSuggestsLetterDigitPatternForReference(): void
    {
        $text = "Orderdatum: 2026-09-10, Referens: AB123";
        $start = mb_strpos($text, 'AB123');
        $end = $start + mb_strlen('AB123');

        $suggestions = $this->suggester->suggest($text, $start, $end);
        $result = $this->tester->test($suggestions[0]->pattern, $text);

        self::assertTrue($result->matched);
        self::assertSame('AB123', $result->value);
    }

    public function testAlwaysIncludesLiteralFallback(): void
    {
        $text = "Kund: Johan Andersson, Stad: Stockholm";
        $start = mb_strpos($text, 'Johan Andersson');
        $end = $start + mb_strlen('Johan Andersson');

        $suggestions = $this->suggester->suggest($text, $start, $end);

        $labels = array_map(fn ($s) => $s->label, $suggestions);
        self::assertContains('Exakt (literal)', $labels);

        $literal = array_values(array_filter($suggestions, fn ($s) => $s->label === 'Exakt (literal)'))[0];
        $result = $this->tester->test($literal->pattern, $text);
        self::assertTrue($result->matched);
        self::assertSame('Johan Andersson', $result->value);
    }

    public function testEmptySelectionReturnsNoSuggestions(): void
    {
        $suggestions = $this->suggester->suggest('Nagot text', 3, 3);
        self::assertSame([], $suggestions);
    }
}
