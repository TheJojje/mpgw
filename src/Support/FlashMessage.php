<?php

declare(strict_types=1);

namespace Mpgw\Support;

/**
 * Session-baserade engangsmeddelanden ("flash") for att visa en SweetAlert2-
 * toast efter en redirect (skapa/andra/ta bort). Satts i en controller precis
 * innan Location-redirecten, plockas upp och tas bort av ViewRenderer::render
 * pa nasta request - se layout.twig for hur de renderas som toasts.
 */
final class FlashMessage
{
    private const SESSION_KEY = 'flash_messages';

    /** @param 'success'|'error' $type */
    public static function add(string $type, string $text): void
    {
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'text' => $text];
    }

    public static function success(string $text): void
    {
        self::add('success', $text);
    }

    public static function error(string $text): void
    {
        self::add('error', $text);
    }

    /**
     * Hamtar och tommer kon - varje meddelande ska bara visas en gang.
     *
     * @return array<int,array{type:string,text:string}>
     */
    public static function consume(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        return $messages;
    }
}
