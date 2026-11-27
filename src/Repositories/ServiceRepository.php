<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class ServiceRepository
{
    public function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM services WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM services ORDER BY id')->fetchAll();
    }
}
