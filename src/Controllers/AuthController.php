<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\Guard;
use App\Auth\RateLimiter;
use App\Auth\Session;
use App\Http\Request;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private RateLimiter $rateLimiter = new RateLimiter(),
        private AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /** @param array<string,string> $params */
    public function login(array $params): void
    {
        $body = Request::jsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        if ($this->rateLimiter->tooManyAttempts($email)) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many attempts - try again later']);
            return;
        }

        $user = $this->auth->attempt($email, $password);
        if ($user === null) {
            $this->rateLimiter->recordFailure($email);
            $this->audit->log(null, 'login_failed');
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        $this->rateLimiter->clear($email);
        Session::login($user);
        $this->audit->log($user['id'], 'login');
        echo json_encode(['user' => $user, 'csrfToken' => Csrf::token()]);
    }

    /** @param array<string,string> $params */
    public function logout(array $params): void
    {
        Guard::requireCsrf();
        $user = Session::user();
        Session::logout();
        $this->audit->log($user['id'] ?? null, 'logout');
        echo json_encode(['status' => 'ok']);
    }

    /** @param array<string,string> $params */
    public function me(array $params): void
    {
        $user = Guard::requireLogin();
        echo json_encode(['user' => $user, 'csrfToken' => Csrf::token()]);
    }
}
