<?php
declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

/** Thrown when a users.email already exists. */
final class DuplicateEmailException extends RuntimeException
{
}
