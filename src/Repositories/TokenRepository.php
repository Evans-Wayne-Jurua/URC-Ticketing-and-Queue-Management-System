<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class TokenRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> oldest first */
    public function waiting(): array
    {
        return Database::connection()
            ->query("SELECT * FROM tokens WHERE status = 'waiting' ORDER BY t1 ASC")
            ->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function serving(): array
    {
        return Database::connection()->query("SELECT * FROM tokens WHERE status = 'serving'")->fetchAll();
    }

    public function servingAtCounter(int $counterNumber): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM tokens WHERE status = 'serving' AND counter_number = :c LIMIT 1"
        );
        $stmt->execute(['c' => $counterNumber]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Atomically assigns the next per-service sequence number and inserts
     * the token - the FOR UPDATE lock on the services row is what stops two
     * kiosk taps for the same service producing the same code.
     */
    public function takeToken(int $serviceId, string $prefix): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT next_seq FROM services WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $serviceId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Unknown service');
            }

            $seq = ((int) $current) + 1;
            $pdo->prepare('UPDATE services SET next_seq = :seq WHERE id = :id')
                ->execute(['seq' => $seq, 'id' => $serviceId]);

            $code = $prefix . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

            $pdo->prepare(
                "INSERT INTO tokens (code, service_id, status, t1) VALUES (:code, :service_id, 'waiting', NOW())"
            )->execute(['code' => $code, 'service_id' => $serviceId]);

            $id = (int) $pdo->lastInsertId();
            $pdo->commit();

            return $this->find($id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Assigns the longest-waiting token to a counter. Null if nothing is waiting. */
    public function callNext(int $counterNumber): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $id = $pdo->query(
                "SELECT id FROM tokens WHERE status = 'waiting' ORDER BY t1 ASC LIMIT 1 FOR UPDATE"
            )->fetchColumn();

            if ($id === false) {
                $pdo->commit();
                return null;
            }

            $pdo->prepare(
                "UPDATE tokens SET status = 'serving', counter_number = :c, t2 = NOW() WHERE id = :id"
            )->execute(['c' => $counterNumber, 'id' => $id]);

            $pdo->commit();

            return $this->find((int) $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function complete(int $counterNumber): ?array
    {
        return $this->finish($counterNumber, 'done');
    }

    public function noShow(int $counterNumber): ?array
    {
        return $this->finish($counterNumber, 'noshow');
    }

    private function finish(int $counterNumber, string $status): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $id = $pdo->prepare(
                "SELECT id FROM tokens WHERE counter_number = :c AND status = 'serving' LIMIT 1 FOR UPDATE"
            );
            $id->execute(['c' => $counterNumber]);
            $tokenId = $id->fetchColumn();

            if ($tokenId === false) {
                $pdo->commit();
                return null;
            }

            $pdo->prepare('UPDATE tokens SET status = :status, t3 = NOW() WHERE id = :id')
                ->execute(['status' => $status, 'id' => $tokenId]);

            $pdo->commit();

            return $this->find((int) $tokenId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{count:int,spanMinutes:float} */
    public function arrivalsSince(DateTimeImmutable $since): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS cnt, MIN(t1) AS oldest FROM tokens WHERE t1 >= :since'
        );
        $stmt->execute(['since' => $since->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch();

        $count = (int) $row['cnt'];
        if ($count === 0 || $row['oldest'] === null) {
            return ['count' => 0, 'spanMinutes' => 0.0];
        }

        $oldestTs = strtotime((string) $row['oldest']);
        $spanMinutes = max(0.0, (time() - $oldestTs) / 60);

        return ['count' => $count, 'spanMinutes' => $spanMinutes];
    }

    public function averageServiceSeconds(): ?float
    {
        $avg = Database::connection()
            ->query("SELECT AVG(TIMESTAMPDIFF(SECOND, t2, t3)) FROM tokens WHERE status = 'done'")
            ->fetchColumn();

        return $avg !== null ? (float) $avg : null;
    }

    public function servedToday(): int
    {
        return (int) Database::connection()
            ->query("SELECT COUNT(*) FROM tokens WHERE status = 'done' AND DATE(t3) = CURDATE()")
            ->fetchColumn();
    }
}
