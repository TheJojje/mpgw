<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Normaliserar ett mails brodtext (text- eller HTML-del) till ren text.
 * Detta ar texten som extraction_rules regex KOR MOT - bade i live-preview
 * i admin-UI:t och vid faktisk produktionsingest, sa de tva garanterat
 * ger samma resultat.
 */
final class HtmlToTextNormalizer
{
    public function normalize(string $body, bool $isHtml): string
    {
        $text = $isHtml ? $this->htmlToText($body) : $body;

        return $this->normalizeWhitespace($text);
    }

    private function htmlToText(string $html): string
    {
        // Gor radbrytande element till faktiska nyrader innan tags strippas,
        // annars klumpas t.ex. tabellceller/rader ihop till en enda lang rad.
        $blockPattern = '#</(p|div|tr|table|li|h[1-6]|br)\s*>#i';
        $html = preg_replace($blockPattern, "$0\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<td[^>]*>#i', "\t", $html) ?? $html;

        // Ta bort script/style-innehall helt, det ar aldrig relevant data.
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text;
    }

    private function normalizeWhitespace(string $text): string
    {
        // Normalisera radbrytningar (CRLF/CR -> LF)
        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;

        // Trimma trailing whitespace per rad, kollapsa upprepade tomrader
        $lines = array_map(static fn (string $line): string => rtrim($line), explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
