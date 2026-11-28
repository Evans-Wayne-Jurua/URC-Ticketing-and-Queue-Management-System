<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDOException;

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

    /** @return array<int,array{id:int,email:string,role:string,created_at:string}> */
    public function all(): array
    {
        return Database::connection()
            ->query('SELECT id, email, role, created_at FROM users ORDER BY created_at DESC')
            ->fetchAll();
    }

    public function create(string $email, string $passwordHash, string $role): int
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO users (email, password_hash, role) VALUES (:email, :password_hash, :role)'
            )->execute(['email' => $email, 'password_hash' => $passwordHash, 'role' => $role]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DuplicateEmailException('An account with that email already exists', 0, $e);
            }
            throw $e;
        }

        return (int) Database::connection()->lastInsertId();
    }
}
