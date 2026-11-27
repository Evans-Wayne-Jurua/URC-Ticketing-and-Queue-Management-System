<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use Throwable;

final class CounterRepository
{
    /** @return array<int,array{number:int,is_open:bool}> */
    public function all(): array
    {
        $rows = Database::connection()->query('SELECT number, is_open FROM counters ORDER BY number')->fetchAll();

        return array_map(
            static fn(array $r): array => ['number' => (int) $r['number'], 'is_open' => (bool) $r['is_open']],
            $rows
        );
    }

    /** @return int[] */
    public function openNumbers(): array
    {
        return array_values(array_map(
            static fn(array $r): int => $r['number'],
            array_filter($this->all(), static fn(array $r): bool => $r['is_open'])
        ));
    }

    public function isOpen(int $number): bool
    {
        $stmt = Database::connection()->prepare('SELECT is_open FROM counters WHERE number = :n');
        $stmt->execute(['n' => $number]);
        $val = $stmt->fetchColumn();

        return $val !== false && (bool) $val;
    }

    // Opening is always allowed - no state can make it unsafe.
    public function open(int $number): void
    {
        Database::connection()
            ->prepare('UPDATE counters SET is_open = 1 WHERE number = :n')
            ->execute(['n' => $number]);
    }

    /**
     * Refuses to close a counter that's actively serving someone (see
     * CLAUDE.md on why "open" is administrative, separate from staffed).
     * The check-then-act is wrapped in a transaction so a call-next can't
     * sneak in between the check and the update.
     */
    public function close(int $number): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM tokens WHERE status = 'serving' AND counter_number = :n FOR UPDATE"
            );
            $stmt->execute(['n' => $number]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->commit();
                return false;
            }

            $pdo->prepare('UPDATE counters SET is_open = 0 WHERE number = :n')->execute(['n' => $number]);
            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
