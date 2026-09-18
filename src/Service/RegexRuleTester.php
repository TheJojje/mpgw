<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Kor ett enskilt regex-monster mot normaliserad text och returnerar
 * traffresultat. ANVANDS BADE av live-preview i admin-UI:t och av
 * RuleEngine vid faktisk produktionsingest - sa de tva garanterat
 * beter sig likadant.
 */
final class RegexRuleTester
{
    public function __construct(
        private readonly int $backtrackLimitBytes = 1_000_000,
    ) {
    }

    public function test(string $pattern, string $text): RuleTestResult
    {
        $delimitedPattern = $this->ensureDelimited($pattern);

        $previousBacktrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) $this->backtrackLimitBytes);

        $start = hrtime(true);
        $matched = @preg_match($delimitedPattern, $text, $matches, PREG_OFFSET_CAPTURE);
        $durationUs = (int) ((hrtime(true) - $start) / 1000);

        if ($previousBacktrack !== false) {
            ini_set('pcre.backtrack_limit', $previousBacktrack);
        }

        $error = preg_last_error();
        if ($matched === false || $error !== PREG_NO_ERROR) {
            return RuleTestResult::error(preg_last_error_msg(), $durationUs);
        }

        if ($matched === 0) {
            return RuleTestResult::noMatch($durationUs);
        }

        // Foredra namngiven grupp "value", annars grupp 1, annars hela traffen.
        if (isset($matches['value'])) {
            [$value, $offset] = $matches['value'];
        } elseif (isset($matches[1])) {
            [$value, $offset] = $matches[1];
        } else {
            [$value, $offset] = $matches[0];
        }

        return RuleTestResult::match($value, $offset, strlen($value), $durationUs);
    }

    private function ensureDelimited(string $pattern): string
    {
        $trimmed = trim($pattern);
        if ($trimmed === '') {
            return '/(?!)/'; // matchar aldrig - tomt monster ska aldrig kunna trigga en traff
        }

        $delimiter = $trimmed[0];
        $closingDelimiters = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
        $closing = $closingDelimiters[$delimiter] ?? $delimiter;

        $looksDelimited = strlen($trimmed) > 1
            && str_ends_with(preg_replace('/[a-zA-Z]*$/', '', $trimmed) ?? '', $closing);

        return $looksDelimited ? $trimmed : '/' . str_replace('/', '\/', $trimmed) . '/us';
    }
}
