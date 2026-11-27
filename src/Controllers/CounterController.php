<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Repositories\CounterRepository;

final class CounterController
{
    public function __construct(private CounterRepository $counters = new CounterRepository())
    {
    }

    /** Public - board and kiosk both need to know which counters are open. */
    public function index(array $params): void
    {
        echo json_encode(['counters' => $this->counters->all()]);
    }

    public function open(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);
        Guard::requireCsrf();

        $this->counters->open((int) ($params['number'] ?? 0));
        echo json_encode(['counters' => $this->counters->all()]);
    }

    public function close(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);
        Guard::requireCsrf();

        if (!$this->counters->close((int) ($params['number'] ?? 0))) {
            http_response_code(409);
            echo json_encode(['error' => 'Counter is currently serving someone']);
            return;
        }

        echo json_encode(['counters' => $this->counters->all()]);
    }
}
