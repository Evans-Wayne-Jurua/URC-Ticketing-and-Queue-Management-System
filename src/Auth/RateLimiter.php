<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Database;
use DateTimeImmutable;

/**
 * Throttles failed logins per identifier (the submitted email - see
 * database/schema.sql for why not IP). Blunts credential-stuffing/brute
 * force without needing a cache service.
 */
final class RateLimiter
{
    public function __construct(
        private int $maxAttempts = 5,
        private int $windowMinutes = 15,
    ) {
    }

    public function tooManyAttempts(string $identifier): bool
    {
        $since = new DateTimeImmutable("-{$this->windowMinutes} minutes");
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = :id AND created_at >= :since'
        );
        $stmt->execute(['id' => $identifier, 'since' => $since->format('Y-m-d H:i:s')]);

        return ((int) $stmt->fetchColumn()) >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier): void
    {
        Database::connection()
            ->prepare('INSERT INTO login_attempts (identifier) VALUES (:id)')
            ->execute(['id' => $identifier]);
    }

    public function clear(string $identifier): void
    {
        Database::connection()
            ->prepare('DELETE FROM login_attempts WHERE identifier = :id')
            ->execute(['id' => $identifier]);
    }
}
