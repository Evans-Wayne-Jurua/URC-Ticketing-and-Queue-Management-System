<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
