<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ServiceRepository;

final class ServiceController
{
    public function __construct(private ServiceRepository $services = new ServiceRepository())
    {
    }

    /** Public - the kiosk needs this to render its service buttons. */
    public function index(array $params): void
    {
        $rows = array_map(static fn(array $s): array => [
            'id' => (int) $s['id'],
            'code' => $s['code'],
            'prefix' => $s['prefix'],
            'labelEn' => $s['label_en'],
            'labelLg' => $s['label_lg'],
        ], $this->services->all());

        echo json_encode(['services' => $rows]);
    }
}
