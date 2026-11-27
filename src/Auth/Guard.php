<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Call at the top of a controller method to gate it. Kiosk and platform
 * display stay public (see CLAUDE.md) - only clerk/supervisor endpoints
 * should ever call these.
 */
final class Guard
{
    /** @return array{id:int,email:string,role:string} */
    public static function requireLogin(): array
    {
        $user = Session::user();
        if ($user === null) {
            self::deny(401, 'Not logged in');
        }

        return $user;
    }

    /**
     * @param string[] $roles
     * @return array{id:int,email:string,role:string}
     */
    public static function requireRole(array $roles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            self::deny(403, 'Not allowed for this role');
        }

        return $user;
    }

    public static function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!Csrf::verify($token)) {
            self::deny(403, 'Invalid or missing CSRF token');
        }
    }

    private static function deny(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }
}
