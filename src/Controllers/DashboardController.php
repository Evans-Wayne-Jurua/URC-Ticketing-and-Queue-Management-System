<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Repositories\CounterRepository;
use App\Repositories\TokenRepository;
use App\Services\QueueingService;

final class DashboardController
{
    public function __construct(
        private TokenRepository $tokens = new TokenRepository(),
        private CounterRepository $counters = new CounterRepository(),
        private QueueingService $queueing = new QueueingService(),
    ) {
    }

    public function show(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);

        $waiting = $this->tokens->waiting();
        $serving = $this->tokens->serving();

        $waitMinutes = array_map(
            static fn(array $t): int => max(0, (int) round((time() - strtotime($t['t1'])) / 60)),
            $waiting
        );

        $lam = $this->queueing->lambdaHat();
        $mu = $this->queueing->muHat();
        $maxCounters = count($this->counters->all());
        $rec = $this->queueing->recommend((float) $lam, (float) $mu, 5.0, $maxCounters);

        $erlangRows = [];
        for ($c = 1; $c <= 5; $c++) {
            $erlangRows[] = ['counters' => $c] + $this->queueing->erlangC((float) $lam, (float) $mu, $c);
        }

        $servingByCounter = [];
        foreach ($serving as $t) {
            $servingByCounter[(int) $t['counter_number']] = $t;
        }
        $countsByCounter = $this->tokens->countsByCounterToday();

        $counterStatus = array_map(function (array $c) use ($servingByCounter, $countsByCounter): array {
            $t = $servingByCounter[$c['number']] ?? null;
            $counts = $countsByCounter[$c['number']] ?? ['done' => 0, 'noshow' => 0];
            return [
                'number' => $c['number'],
                'isOpen' => $c['is_open'],
                'serving' => $t['code'] ?? null,
                'servedToday' => $counts['done'],
                'noShowsToday' => $counts['noshow'],
            ];
        }, $this->counters->all());

        echo json_encode([
            'inQueue' => count($waiting),
            'averageWaitMinutes' => $waitMinutes === [] ? 0 : (int) round(array_sum($waitMinutes) / count($waitMinutes)),
            'longestWaitMinutes' => $waitMinutes === [] ? 0 : max($waitMinutes),
            'servedToday' => $this->tokens->servedToday(),
            'lambda' => $lam,
            'mu' => $mu,
            'recommendation' => $rec,
            'erlangRows' => $erlangRows,
            'counterStatus' => $counterStatus,
        ]);
    }
}
