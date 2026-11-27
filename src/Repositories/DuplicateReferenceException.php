<?php
declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

/** Thrown when a payment_reference has already been used on another sale. */
final class DuplicateReferenceException extends RuntimeException
{
}
