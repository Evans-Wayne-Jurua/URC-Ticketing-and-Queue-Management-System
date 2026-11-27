<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AuthService
{
    public function __construct(private UserRepository $users = new UserRepository())
    {
    }

    /** @return array{id:int,email:string,role:string}|null */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        return ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']];
    }
}
