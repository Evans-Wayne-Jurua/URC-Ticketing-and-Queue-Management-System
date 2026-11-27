<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class AuditLogRepository
{
    public function log(?int $userId, string $action, ?int $tokenId = null, ?int $counterNumber = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO audit_log (user_id, action, token_id, counter_number) VALUES (:user_id, :action, :token_id, :counter_number)'
        )->execute([
            'user_id' => $userId,
            'action' => $action,
            'token_id' => $tokenId,
            'counter_number' => $counterNumber,
        ]);
    }
}
