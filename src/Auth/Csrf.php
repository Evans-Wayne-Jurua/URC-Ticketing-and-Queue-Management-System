<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Session-bound CSRF token. Needed because auth uses cookies - without it,
 * a malicious page could ride the browser's cookie to trigger state-changing
 * requests (logout, later: call-next/complete-sale) on the user's behalf.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        return $token !== null
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
