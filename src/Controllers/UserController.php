<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Auth\Session;
use App\Http\Request;
use App\Repositories\AuditLogRepository;
use App\Repositories\DuplicateEmailException;
use App\Repositories\UserRepository;

final class UserController
{
    // Admin is deliberately not creatable here, regardless of who's asking -
    // reserved for database/seed_users.php, so a supervisor can't use this
    // endpoint to grant an account (their own or anyone else's) a tier above
    // what the UI is meant to hand out.
    private const CREATABLE_ROLES = ['clerk', 'supervisor'];

    public function __construct(
        private UserRepository $users = new UserRepository(),
        private AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /** Supervisor/admin - list staff accounts (no password hashes). */
    public function index(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);

        $rows = array_map(static fn(array $u): array => [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'role' => $u['role'],
            'createdAt' => $u['created_at'],
        ], $this->users->all());

        echo json_encode(['users' => $rows]);
    }

    /** Supervisor/admin - create a clerk or supervisor account. */
    public function create(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);
        Guard::requireCsrf();

        $body = Request::jsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? '');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            http_response_code(422);
            echo json_encode(['error' => 'A valid email is required']);
            return;
        }
        if (strlen($password) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            return;
        }
        if (!in_array($role, self::CREATABLE_ROLES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Role must be clerk or supervisor']);
            return;
        }

        try {
            $id = $this->users->create($email, password_hash($password, PASSWORD_DEFAULT), $role);
        } catch (DuplicateEmailException $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        $actor = Session::user();
        $this->audit->log($actor['id'] ?? null, 'create_account');
        echo json_encode(['user' => ['id' => $id, 'email' => $email, 'role' => $role]]);
    }
}
