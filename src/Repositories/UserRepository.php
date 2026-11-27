<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class UserRepository
{
    /** @return array{id:int,email:string,password_hash:string,role:string}|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, password_hash, role FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }
}
