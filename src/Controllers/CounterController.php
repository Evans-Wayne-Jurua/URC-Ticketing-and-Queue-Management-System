<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Auth\Session;
use App\Repositories\AuditLogRepository;
use App\Repositories\CounterRepository;

final class CounterController
{
    public function __construct(
        private CounterRepository $counters = new CounterRepository(),
        private AuditLogRepository $audit = new AuditLogRepository(),
    ) {
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

        $number = (int) ($params['number'] ?? 0);
        $this->counters->open($number);

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, 'open_counter', null, $number);
        echo json_encode(['counters' => $this->counters->all()]);
    }

    public function close(array $params): void
    {
        Guard::requireRole(['supervisor', 'admin']);
        Guard::requireCsrf();

        $number = (int) ($params['number'] ?? 0);
        if (!$this->counters->close($number)) {
            http_response_code(409);
            echo json_encode(['error' => 'Counter is currently serving someone']);
            return;
        }

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, 'close_counter', null, $number);
        echo json_encode(['counters' => $this->counters->all()]);
    }
}
