<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CounterRepository;
use App\Repositories\TokenRepository;

final class BoardController
{
    public function __construct(
        private TokenRepository $tokens = new TokenRepository(),
        private CounterRepository $counters = new CounterRepository(),
    ) {
    }

    /** Public - the platform display screen. */
    public function show(array $params): void
    {
        $waiting = $this->tokens->waiting();
        $serving = $this->tokens->serving();
        $open = $this->counters->openNumbers();

        $servingByCounter = [];
        foreach ($serving as $t) {
            $servingByCounter[(int) $t['counter_number']] = $t['code'];
        }

        $nowServing = array_map(
            static fn(int $c): array => ['counter' => $c, 'code' => $servingByCounter[$c] ?? null],
            $open
        );

        $typicalWaitMin = $waiting === []
            ? 0
            : max(0, (int) round((time() - strtotime($waiting[0]['t1'])) / 60));

        echo json_encode([
            'nowServing' => $nowServing,
            'nextCodes' => array_map(static fn(array $t): string => $t['code'], array_slice($waiting, 0, 6)),
            'typicalWaitMinutes' => $typicalWaitMin,
            'waitingCount' => count($waiting),
        ]);
    }
}
