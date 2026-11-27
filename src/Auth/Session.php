<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Thin wrapper around native PHP sessions - chosen over JWT because the
 * frontend and API share one origin (see CLAUDE.md), which makes cookie
 * sessions simpler and avoids refresh-token handling entirely.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($_SERVER['HTTPS']), // true automatically once served over HTTPS (Azure)
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('urc_session');
        session_start();
    }

    /** @param array{id:int,email:string,role:string} $user */
    public static function login(array $user): void
    {
        // regenerate the session id on privilege change - prevents session fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /** @return array{id:int,email:string,role:string}|null */
    public static function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return [
            'id' => (int) $_SESSION['user_id'],
            'email' => (string) $_SESSION['email'],
            'role' => (string) $_SESSION['role'],
        ];
    }
}
