<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Auth\Guard;
use App\Auth\Session;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(private AuthService $auth = new AuthService())
    {
    }

    /** @param array<string,string> $params */
    public function login(array $params): void
    {
        $body = $this->jsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        $user = $this->auth->attempt($email, $password);
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        Session::login($user);
        echo json_encode(['user' => $user, 'csrfToken' => Csrf::token()]);
    }

    /** @param array<string,string> $params */
    public function logout(array $params): void
    {
        Guard::requireCsrf();
        Session::logout();
        echo json_encode(['status' => 'ok']);
    }

    /** @param array<string,string> $params */
    public function me(array $params): void
    {
        $user = Guard::requireLogin();
        echo json_encode(['user' => $user, 'csrfToken' => Csrf::token()]);
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
