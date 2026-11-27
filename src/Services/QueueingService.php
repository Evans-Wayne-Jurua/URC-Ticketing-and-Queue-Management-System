<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TokenRepository;
use DateTimeImmutable;

/**
 * Port of public/js/domain/queueing.js - same formulas, same rounding, same
 * floors - so the two must never be allowed to drift apart silently. If you
 * change a number here, change it there too (and vice versa).
 */
final class QueueingService
{
    public function __construct(private TokenRepository $tokens = new TokenRepository())
    {
    }

    /**
     * @return array{a:float,rho:float,pWait:float,wq:float|null} wq is null
     *         (not INF - JSON can't encode that) when the target is unreachable.
     */
    public function erlangC(float $lam, float $mu, int $c): array
    {
        $a = $lam / $mu;
        $rho = $lam / ($c * $mu);

        if ($c * $mu <= $lam) {
            return ['a' => $a, 'rho' => $rho, 'pWait' => 1.0, 'wq' => null];
        }

        $sum = 0.0;
        $term = 1.0;
        for ($k = 0; $k < $c; $k++) {
            if ($k > 0) {
                $term = $term * $a / $k;
            }
            $sum += $term;
        }
        $last = $term * $a / $c;
        $num = $last * ($c / ($c - $a));
        $pWait = $num / ($sum + $num);

        return ['a' => $a, 'rho' => $rho, 'pWait' => $pWait, 'wq' => $pWait / ($c * $mu - $lam)];
    }

    // arrivals/hour, from T1 stamps in a 20-minute window
    public function lambdaHat(): int
    {
        $arrivals = $this->tokens->arrivalsSince(new DateTimeImmutable('-20 minutes'));
        if ($arrivals['count'] === 0) {
            return 12;
        }

        $spanMin = max(8.0, $arrivals['spanMinutes']);

        return max(10, (int) round($arrivals['count'] * 60 / $spanMin));
    }

    // sales/hour/counter, from T3-T2 on completed sales
    public function muHat(): int
    {
        $avg = $this->tokens->averageServiceSeconds();
        if ($avg === null || $avg <= 0) {
            return 12;
        }

        return max(4, (int) round(3600 / $avg));
    }

    /** @return array{c:int,r:array,short:bool} */
    public function recommend(float $lam, float $mu, float $targetMin, int $maxCounters): array
    {
        for ($c = 1; $c <= $maxCounters; $c++) {
            $r = $this->erlangC($lam, $mu, $c);
            if ($r['wq'] !== null && $r['wq'] * 60 <= $targetMin) {
                return ['c' => $c, 'r' => $r, 'short' => false];
            }
        }

        return ['c' => $maxCounters, 'r' => $this->erlangC($lam, $mu, $maxCounters), 'short' => true];
    }
}
