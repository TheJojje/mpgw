<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Matchar ett inkommet mail mot ratt mall for applikationen, baserat pa
 * avsandaradress + amnesregex. Mallar provas i prioritetsordning,
 * forsta fullstandiga traff vinner.
 */
final class TemplateMatcher
{
    /**
     * @param array<int,array{id:int,from_pattern:?string,from_match_type:string,subject_regex:?string,priority:int}> $templates
     *   Mallar for EN applikation, forvantas redan filtrerade pa is_active=1 och sorterade pa priority ASC.
     */
    public function match(array $templates, string $fromAddress, string $subject): ?array
    {
        foreach ($templates as $template) {
            if ($this->fromMatches($template, $fromAddress) && $this->subjectMatches($template, $subject)) {
                return $template;
            }
        }

        return null;
    }

    private function fromMatches(array $template, string $fromAddress): bool
    {
        $pattern = $template['from_pattern'] ?? null;
        if ($pattern === null || $pattern === '') {
            return true; // inget villkor = matchar alla avsandare
        }

        return match ($template['from_match_type']) {
            'exact' => strcasecmp($fromAddress, $pattern) === 0,
            'regex' => @preg_match($pattern, $fromAddress) === 1,
            default => stripos($fromAddress, $pattern) !== false, // 'contains'
        };
    }

    private function subjectMatches(array $template, string $subject): bool
    {
        $regex = $template['subject_regex'] ?? null;
        if ($regex === null || $regex === '') {
            return true; // inget villkor = matchar alla amnen
        }

        return @preg_match($regex, $subject) === 1;
    }
}
