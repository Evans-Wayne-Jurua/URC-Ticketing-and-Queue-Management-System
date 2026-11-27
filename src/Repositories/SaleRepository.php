<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class SaleRepository
{
    // The total is always server-computed from classFee*qty, never taken
    // from the client - see claudeSecure.md on why money totals are never
    // trusted from the browser.
    public function record(int $tokenId, string $trip, int $classFee, int $qty, string $paymentMethod): array
    {
        $total = $classFee * $qty;

        Database::connection()->prepare(
            'INSERT INTO sales (token_id, trip, class_fee, qty, payment_method, total)
             VALUES (:token_id, :trip, :class_fee, :qty, :payment_method, :total)'
        )->execute([
            'token_id' => $tokenId,
            'trip' => $trip,
            'class_fee' => $classFee,
            'qty' => $qty,
            'payment_method' => $paymentMethod,
            'total' => $total,
        ]);

        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'tokenId' => $tokenId,
            'trip' => $trip,
            'classFee' => $classFee,
            'qty' => $qty,
            'paymentMethod' => $paymentMethod,
            'total' => $total,
        ];
    }
}
