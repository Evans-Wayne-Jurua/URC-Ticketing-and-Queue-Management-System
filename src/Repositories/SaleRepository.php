<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDOException;

final class SaleRepository
{
    // The total is always server-computed from classFee*qty, never taken
    // from the client - see claudeSecure.md on why money totals are never
    // trusted from the browser.
    public function record(
        int $tokenId,
        string $trip,
        int $classFee,
        int $qty,
        string $paymentMethod,
        ?string $paymentReference,
    ): array {
        $total = $classFee * $qty;

        try {
            Database::connection()->prepare(
                'INSERT INTO sales (token_id, trip, class_fee, qty, payment_method, payment_reference, total)
                 VALUES (:token_id, :trip, :class_fee, :qty, :payment_method, :payment_reference, :total)'
            )->execute([
                'token_id' => $tokenId,
                'trip' => $trip,
                'class_fee' => $classFee,
                'qty' => $qty,
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'total' => $total,
            ]);
        } catch (PDOException $e) {
            // SQLSTATE 23000 = integrity constraint violation. The only
            // constraint realistically hit here (the token_id FK can't
            // fail - it's always a token this same request just completed)
            // is uq_sales_payment_reference, so this is the clean,
            // DB-enforced backstop behind the controller's own pre-check -
            // the real protection against a race between the two.
            if ($e->getCode() === '23000') {
                throw new DuplicateReferenceException('This transaction reference has already been used', 0, $e);
            }
            throw $e;
        }

        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'tokenId' => $tokenId,
            'trip' => $trip,
            'classFee' => $classFee,
            'qty' => $qty,
            'paymentMethod' => $paymentMethod,
            'paymentReference' => $paymentReference,
            'total' => $total,
        ];
    }

    public function referenceExists(string $reference): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM sales WHERE payment_reference = :ref LIMIT 1');
        $stmt->execute(['ref' => $reference]);

        return $stmt->fetchColumn() !== false;
    }
}
