<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Guard;
use App\Auth\Session;
use App\Http\Request;
use App\Repositories\AuditLogRepository;
use App\Repositories\CounterRepository;
use App\Repositories\DuplicateReferenceException;
use App\Repositories\SaleRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\TokenRepository;
use App\Services\QueueingService;

final class TokenController
{
    // Allow-lists for sale details - these mirror the fixed <option> values
    // in public/index.html's Quick sale form. A client sending anything
    // outside these gets rejected rather than silently stored.
    private const ALLOWED_TRIPS = [
        '17:30 Kampala → Mukono',
        '18:30 Mukono → Kampala',
        '19:50 Kampala → Mukono',
    ];
    private const ALLOWED_CLASS_FEES = [2000, 5000];
    private const ALLOWED_PAYMENT_METHODS = ['Cash', 'MTN MoMo', 'Airtel Money'];
    // Methods that generate a payer-visible transaction reference this
    // system can record (not verify - see claudeSecure.md). Cash has none.
    private const MOBILE_MONEY_METHODS = ['MTN MoMo', 'Airtel Money'];

    public function __construct(
        private TokenRepository $tokens = new TokenRepository(),
        private ServiceRepository $services = new ServiceRepository(),
        private CounterRepository $counters = new CounterRepository(),
        private QueueingService $queueing = new QueueingService(),
        private AuditLogRepository $audit = new AuditLogRepository(),
        private SaleRepository $sales = new SaleRepository(),
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

    /**
     * Completes whoever is being served at this counter. Optionally takes
     * sale details (trip/classFee/qty/paymentMethod) in the body - required
     * in full if the token being completed is a "ticket" service, rejected
     * if sent for any other service (nothing to sell there), ignored (no
     * sale recorded) if omitted entirely.
     */
    public function complete(array $params): void
    {
        Guard::requireRole(['clerk', 'supervisor', 'admin']);
        Guard::requireCsrf();

        $counter = (int) ($params['number'] ?? 0);
        $body = Request::jsonBody();
        $hasSaleData = $body !== [];

        $serving = $this->tokens->servingAtCounter($counter);
        if ($serving === null) {
            http_response_code(409);
            echo json_encode(['error' => 'No one is being served at that counter']);
            return;
        }

        $service = $this->services->find((int) $serving['service_id']);
        $isTicketSale = $service !== null && $service['code'] === 'ticket';

        if ($hasSaleData && !$isTicketSale) {
            http_response_code(422);
            echo json_encode(['error' => 'This service does not take a sale']);
            return;
        }

        $trip = (string) ($body['trip'] ?? '');
        $classFee = (int) ($body['classFee'] ?? 0);
        $qty = (int) ($body['qty'] ?? 0);
        $paymentMethod = (string) ($body['paymentMethod'] ?? '');
        $paymentReference = isset($body['paymentReference']) ? trim((string) $body['paymentReference']) : null;
        $isMobileMoney = in_array($paymentMethod, self::MOBILE_MONEY_METHODS, true);

        if ($hasSaleData && (
            !in_array($trip, self::ALLOWED_TRIPS, true)
            || !in_array($classFee, self::ALLOWED_CLASS_FEES, true)
            || $qty < 1 || $qty > 10
            || !in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)
        )) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid sale details']);
            return;
        }

        // Mobile money must carry a plausible reference; Cash must not carry
        // one at all (nothing for it to refer to) - keeps the contract
        // unambiguous rather than silently ignoring a stray value.
        if ($hasSaleData && $isMobileMoney
            && ($paymentReference === null || !preg_match('/^[A-Za-z0-9._\-]{4,50}$/', $paymentReference))
        ) {
            http_response_code(422);
            echo json_encode(['error' => 'A valid transaction reference is required for mobile money']);
            return;
        }
        if ($hasSaleData && !$isMobileMoney && $paymentReference !== null && $paymentReference !== '') {
            http_response_code(422);
            echo json_encode(['error' => 'Cash payments do not take a transaction reference']);
            return;
        }
        if ($hasSaleData && $isMobileMoney && $this->sales->referenceExists($paymentReference)) {
            http_response_code(409);
            echo json_encode(['error' => 'This transaction reference has already been used for another sale']);
            return;
        }

        $token = $this->tokens->complete($counter);
        if ($token === null) {
            // the check above should prevent this, but stay defensive against
            // a race with another request completing/no-showing first
            http_response_code(409);
            echo json_encode(['error' => 'No one is being served at that counter']);
            return;
        }

        $sale = null;
        if ($hasSaleData) {
            try {
                $sale = $this->sales->record(
                    (int) $token['id'],
                    $trip,
                    $classFee,
                    $qty,
                    $paymentMethod,
                    $isMobileMoney ? $paymentReference : null,
                );
            } catch (DuplicateReferenceException $e) {
                // Lost a race against another request using the same
                // reference between the pre-check above and this insert -
                // the token is already completed at this point (correctly:
                // the service was still rendered), the sale just isn't
                // recorded. Surfaced clearly rather than silently dropped.
                $user = Session::user();
                $this->audit->log($user['id'] ?? null, 'complete_sale', (int) $token['id'], $counter);
                http_response_code(409);
                echo json_encode([
                    'token' => $token,
                    'sale' => null,
                    'error' => 'This transaction reference has already been used for another sale',
                ]);
                return;
            }
        }

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, 'complete_sale', (int) $token['id'], $counter);
        echo json_encode(['token' => $token, 'sale' => $sale]);
    }

    public function noShow(array $params): void
    {
        Guard::requireRole(['clerk', 'supervisor', 'admin']);
        Guard::requireCsrf();

        $counter = (int) ($params['number'] ?? 0);
        $token = $this->tokens->noShow($counter);

        if ($token === null) {
            http_response_code(409);
            echo json_encode(['error' => 'No one is being served at that counter']);
            return;
        }

        $user = Session::user();
        $this->audit->log($user['id'] ?? null, 'no_show', (int) $token['id'], $counter);
        echo json_encode(['token' => $token]);
    }
}
