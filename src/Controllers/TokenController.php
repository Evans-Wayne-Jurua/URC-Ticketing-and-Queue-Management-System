<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Auth\Session;
use App\Http\Request;
use App\Repositories\AuditLogRepository;
use App\Repositories\CounterRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\TokenRepository;
use App\Services\QueueingService;

final class TokenController
{
    public function __construct(
        private TokenRepository $tokens = new TokenRepository(),
        private ServiceRepository $services = new ServiceRepository(),
        private CounterRepository $counters = new CounterRepository(),
        private QueueingService $queueing = new QueueingService(),
        private AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /** Public - the kiosk screen. POST /tokens {"service": "ticket"} */
    public function take(array $params): void
    {
        $body = Request::jsonBody();
        $code = (string) ($body['service'] ?? '');

        $service = $this->services->findByCode($code);
        if ($service === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Unknown service']);
            return;
        }

        $token = $this->tokens->takeToken((int) $service['id'], $service['prefix']);
        $this->audit->log(null, 'take_token', (int) $token['id']);

        $ahead = max(0, count($this->tokens->waiting()) - 1);
        $openCount = max(1, count($this->counters->openNumbers()));
        $mu = $this->queueing->muHat();
        $estWaitMin = max(1, (int) round($ahead / $openCount * (3600 / $mu) / 60));

        echo json_encode(['token' => $token, 'ahead' => $ahead, 'estWaitMinutes' => $estWaitMin]);
    }

    /** Clerk/supervisor - assigns the longest-waiting token to this counter. */
    public function callNext(array $params): void
    {
        Guard::requireRole(['clerk', 'supervisor', 'admin']);
        Guard::requireCsrf();

        $counter = (int) ($params['number'] ?? 0);

        if (!$this->counters->isOpen($counter)) {
            http_response_code(422);
            echo json_encode(['error' => 'Counter is not open']);
            return;
        }
        if ($this->tokens->servingAtCounter($counter) !== null) {
            http_response_code(409);
            echo json_encode(['error' => 'Counter is already serving someone']);
            return;
        }

        $token = $this->tokens->callNext($counter);
        if ($token === null) {
            http_response_code(204);
            return;
        }

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, 'call_next', (int) $token['id'], $counter);
        echo json_encode(['token' => $token]);
    }

    public function complete(array $params): void
    {
        $this->finish($params, 'done');
    }

    public function noShow(array $params): void
    {
        $this->finish($params, 'noshow');
    }

    private function finish(array $params, string $status): void
    {
        Guard::requireRole(['clerk', 'supervisor', 'admin']);
        Guard::requireCsrf();

        $counter = (int) ($params['number'] ?? 0);
        $token = $status === 'done' ? $this->tokens->complete($counter) : $this->tokens->noShow($counter);

        if ($token === null) {
            http_response_code(409);
            echo json_encode(['error' => 'No one is being served at that counter']);
            return;
        }

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, $status === 'done' ? 'complete_sale' : 'no_show', (int) $token['id'], $counter);
        echo json_encode(['token' => $token]);
    }
}
