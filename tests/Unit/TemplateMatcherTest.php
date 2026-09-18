<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use Mpgw\Service\TemplateMatcher;
use PHPUnit\Framework\TestCase;

final class TemplateMatcherTest extends TestCase
{
    private TemplateMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new TemplateMatcher();
    }

    public function testFirstMatchingTemplateWinsByPriority(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => 'orders@acme.se', 'from_match_type' => 'contains', 'subject_regex' => '/^Order/i', 'priority' => 10],
            ['id' => 2, 'from_pattern' => 'orders@acme.se', 'from_match_type' => 'contains', 'subject_regex' => null, 'priority' => 20],
        ];

        $match = $this->matcher->match($templates, 'orders@acme.se', 'Order 1234 bekraftad');

        self::assertSame(1, $match['id']);
    }

    public function testFallsThroughToLowerPriorityWhenSubjectDoesNotMatch(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => 'orders@acme.se', 'from_match_type' => 'contains', 'subject_regex' => '/^Order/i', 'priority' => 10],
            ['id' => 2, 'from_pattern' => 'orders@acme.se', 'from_match_type' => 'contains', 'subject_regex' => null, 'priority' => 20],
        ];

        $match = $this->matcher->match($templates, 'orders@acme.se', 'Faktura 5678');

        self::assertSame(2, $match['id']);
    }

    public function testReturnsNullWhenNoTemplateMatches(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => 'orders@acme.se', 'from_match_type' => 'exact', 'subject_regex' => null, 'priority' => 10],
        ];

        $match = $this->matcher->match($templates, 'other@example.com', 'Nagot amne');

        self::assertNull($match);
    }

    public function testExactFromMatchTypeIsCaseInsensitive(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => 'Orders@Acme.se', 'from_match_type' => 'exact', 'subject_regex' => null, 'priority' => 10],
        ];

        $match = $this->matcher->match($templates, 'orders@acme.se', 'nagot');

        self::assertSame(1, $match['id']);
    }

    public function testRegexFromMatchType(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => '/@(acme|beta)\.se$/i', 'from_match_type' => 'regex', 'subject_regex' => null, 'priority' => 10],
        ];

        self::assertNotNull($this->matcher->match($templates, 'noreply@beta.se', 'x'));
        self::assertNull($this->matcher->match($templates, 'noreply@gamma.se', 'x'));
    }

    public function testNoConditionsMatchesEverything(): void
    {
        $templates = [
            ['id' => 1, 'from_pattern' => null, 'from_match_type' => 'contains', 'subject_regex' => null, 'priority' => 10],
        ];

        $match = $this->matcher->match($templates, 'anyone@anywhere.com', 'vilket amne som helst');

        self::assertSame(1, $match['id']);
    }
}
