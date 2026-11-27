<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use PDOException;

final class HealthController
{
    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        try {
            Database::connection()->query('SELECT 1');
            echo json_encode([
                'status' => 'ok',
                'database' => 'connected',
                'time' => date(DATE_ATOM),
            ]);
        } catch (PDOException $e) {
            error_log('DB health check failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'database' => 'unreachable',
            ]);
        }
    }
}
