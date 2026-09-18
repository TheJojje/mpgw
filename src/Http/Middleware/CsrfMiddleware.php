<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

/**
 * Enkel CSRF-tokenhantering for adminvyns formular (POST/PUT/DELETE).
 * Token lagras i sessionen, formular postar tillbaka den som falt
 * "csrf_token", verifieras med hash_equals for att undvika timing-attacker.
 */
final class CsrfMiddleware
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $submittedToken): bool
    {
        $expected = $_SESSION['csrf_token'] ?? null;
        if ($expected === null || $submittedToken === null) {
            return false;
        }

        return hash_equals($expected, $submittedToken);
    }
}
