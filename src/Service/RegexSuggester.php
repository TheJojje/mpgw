<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Foreslar regex-monster utifran en markering (highlight) i ett mails
 * normaliserade text. Anvandaren markerar t.ex. "2026-09-10" i texten
 * "Orderdatum: 2026-09-10, Referens: AB123" och far tillbaka flera
 * rangordnade forslag pa monster som fangar samma falt aven i framtida
 * mail dar sjalva vardet varierar.
 *
 * Forslaget ar bara en startpunkt - det faktiska monstret som sparas
 * i extraction_rules.pattern kan alltid redigeras fritt av anvandaren
 * och testas live via RegexRuleTester innan sparning.
 */
final class RegexSuggester
{
    private const MAX_CONTEXT_CHARS = 40;

    /**
     * @return RegexSuggestion[] rangordnade forslag, mest generaliserat forst
     */
    public function suggest(string $fullText, int $selectionStart, int $selectionEnd): array
    {
        $selectedText = mb_substr($fullText, $selectionStart, $selectionEnd - $selectionStart);
        if ($selectedText === '') {
            return [];
        }

        $prefix = $this->extractPrefixContext($fullText, $selectionStart);
        $suffix = $this->extractSuffixContext($fullText, $selectionEnd);

        $escapedPrefix = $this->escapeContext($prefix);
        $escapedSuffix = $this->escapeContext($suffix);

        $suggestions = [];

        $generalized = $this->generalize($selectedText);
        if ($generalized !== null) {
            $suggestions[] = new RegexSuggestion(
                label: $generalized['label'],
                pattern: $this->buildPattern($escapedPrefix, $generalized['pattern'], $escapedSuffix),
                description: 'Generaliserat - fångar samma typ av värde även om det ändras (t.ex. annat datum/nummer).',
            );
        }

        // Semi-generaliserat: behall langd/teckenklass men inte exakt vardet.
        $semi = $this->semiGeneralize($selectedText);
        if ($semi !== null && $semi !== ($generalized['pattern'] ?? null)) {
            $suggestions[] = new RegexSuggestion(
                label: 'Samma teckenklass',
                pattern: $this->buildPattern($escapedPrefix, $semi, $escapedSuffix),
                description: 'Fångar värden med samma typ av tecken (siffror/bokstäver) men annan längd.',
            );
        }

        // Alltid ett literalt fallback-forslag - garanterat matchar just detta exempel.
        $suggestions[] = new RegexSuggestion(
            label: 'Exakt (literal)',
            pattern: $this->buildPattern($escapedPrefix, preg_quote($selectedText, '/'), $escapedSuffix),
            description: 'Matchar bara exakt detta värde - robust mot omgivande text men fångar inte andra värden.',
        );

        return $suggestions;
    }

    private function extractPrefixContext(string $text, int $selectionStart): string
    {
        $start = max(0, $selectionStart - self::MAX_CONTEXT_CHARS);
        $context = mb_substr($text, $start, $selectionStart - $start);

        // Foredra att borja efter senaste radbrytning - kortare, stabilare kontext.
        $lastNewline = mb_strrpos($context, "\n");
        if ($lastNewline !== false) {
            $context = mb_substr($context, $lastNewline + 1);
        }

        // Trimma inledande whitespace, men behall etikett+skiljetecken (t.ex. "Orderdatum: ").
        return ltrim($context);
    }

    private function extractSuffixContext(string $text, int $selectionEnd): string
    {
        $context = mb_substr($text, $selectionEnd, self::MAX_CONTEXT_CHARS);

        $firstNewline = mb_strpos($context, "\n");
        if ($firstNewline !== false) {
            $context = mb_substr($context, 0, $firstNewline);
        }

        // Klipp vid nasta tydliga faltgrans om en finns nara (", " eller ";").
        if (preg_match('/^([^,;]{0,30})[,;]/u', $context, $m) === 1) {
            $context = $m[1];
        }

        return rtrim($context);
    }

    private function escapeContext(string $context): string
    {
        $escaped = preg_quote($context, '/');
        // Gor whitespace-sekvenser flexibla - mail varierar ofta i antal
        // mellanslag/tabbar utan att det ska spracka monstret.
        return (string) preg_replace('/(\\\\ |\\\\t)+/', '\s+', $escaped);
    }

    /**
     * @return array{label:string,pattern:string}|null
     */
    private function generalize(string $value): ?array
    {
        $trimmed = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return ['label' => 'Datum (ISO, YYYY-MM-DD)', 'pattern' => '\d{4}-\d{2}-\d{2}'];
        }

        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $trimmed) === 1) {
            return ['label' => 'Datum (DD/MM/YYYY)', 'pattern' => '\d{1,2}/\d{1,2}/\d{4}'];
        }

        if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $trimmed) === 1) {
            return ['label' => 'Datum (DD-MM-YYYY)', 'pattern' => '\d{1,2}-\d{1,2}-\d{4}'];
        }

        if (preg_match('/^\d+[.,]\d+$/', $trimmed) === 1) {
            return ['label' => 'Decimaltal', 'pattern' => '\d+[.,]\d+'];
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return ['label' => 'Heltal', 'pattern' => '\d+'];
        }

        if (preg_match('/^[A-Za-z]+\d+$/', $trimmed) === 1) {
            $letters = strlen((string) preg_replace('/\d+$/', '', $trimmed));
            $digits = strlen((string) preg_replace('/^[A-Za-z]+/', '', $trimmed));
            return [
                'label' => 'Bokstäver + siffror (t.ex. referens)',
                'pattern' => sprintf('[A-Za-z]{%d}\d{%d}', $letters, $digits),
            ];
        }

        if (preg_match('/^[\w.+-]+@[\w.-]+\.\w+$/u', $trimmed) === 1) {
            return ['label' => 'E-postadress', 'pattern' => '[\w.+-]+@[\w.-]+\.\w+'];
        }

        return null;
    }

    private function semiGeneralize(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // Bygg ett monster utifran teckenklass per tecken, kollapsat till kvantifierare.
        // T.ex. "AB123" -> [A-Za-z]{2}\d{3}
        $classes = [];
        foreach (mb_str_split($trimmed) as $char) {
            if (preg_match('/[A-Za-z]/u', $char) === 1) {
                $classes[] = 'L';
            } elseif (preg_match('/\d/u', $char) === 1) {
                $classes[] = 'D';
            } else {
                $classes[] = 'O'; // ovrigt (skiljetecken etc.)
            }
        }

        // Om det ar en blandning av bara L/D (inga ovriga tecken), generalisera per lopande sekvens.
        if (in_array('O', $classes, true)) {
            return null;
        }

        $pattern = '';
        $currentClass = null;
        $count = 0;
        $flush = function () use (&$pattern, &$currentClass, &$count): void {
            if ($currentClass === null) {
                return;
            }
            $charClass = $currentClass === 'L' ? '[A-Za-z]' : '\d';
            $pattern .= $count === 1 ? $charClass : $charClass . '{' . $count . '}';
        };

        foreach ($classes as $class) {
            if ($class !== $currentClass) {
                $flush();
                $currentClass = $class;
                $count = 1;
            } else {
                $count++;
            }
        }
        $flush();

        return $pattern !== '' ? $pattern : null;
    }

    private function buildPattern(string $escapedPrefix, string $valuePattern, string $escapedSuffix): string
    {
        return sprintf('/%s(?<value>%s)%s/us', $escapedPrefix, $valuePattern, $escapedSuffix);
    }
}
