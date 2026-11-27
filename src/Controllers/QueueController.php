<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Repositories\TokenRepository;

final class QueueController
{
    public function __construct(private TokenRepository $tokens = new TokenRepository())
    {
    }

    /** Clerk/supervisor - the full waiting list + who's being served where. */
    public function show(array $params): void
    {
        Guard::requireRole(['clerk', 'supervisor', 'admin']);

        $waiting = array_map(static fn(array $t): array => [
            'id' => (int) $t['id'],
            'code' => $t['code'],
            'serviceId' => (int) $t['service_id'],
            'waitMinutes' => max(0, (int) round((time() - strtotime($t['t1'])) / 60)),
        ], $this->tokens->waiting());

        $serving = array_map(static fn(array $t): array => [
            'id' => (int) $t['id'],
            'code' => $t['code'],
            'serviceId' => (int) $t['service_id'],
            'counter' => (int) $t['counter_number'],
            'waitedMinutes' => max(0, (int) round((strtotime($t['t2']) - strtotime($t['t1'])) / 60)),
            'servingMinutes' => max(0, (int) round((time() - strtotime($t['t2'])) / 60)),
        ], $this->tokens->serving());

        echo json_encode(['waiting' => $waiting, 'serving' => $serving]);
    }
}
