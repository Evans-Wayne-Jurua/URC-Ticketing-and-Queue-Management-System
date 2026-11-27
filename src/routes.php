<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BoardController;
use App\Controllers\CounterController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\QueueController;
use App\Controllers\ServiceController;
use App\Controllers\TokenController;
use App\Http\Router;

/** @var Router $router */
$router->get('/health', [new HealthController(), 'show']);

$auth = new AuthController();
$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/logout', [$auth, 'logout']);
$router->get('/auth/me', [$auth, 'me']);

$router->get('/services', [new ServiceController(), 'index']);
$router->get('/board', [new BoardController(), 'show']);
$router->get('/queue', [new QueueController(), 'show']);
$router->get('/dashboard', [new DashboardController(), 'show']);

$counters = new CounterController();
$router->get('/counters', [$counters, 'index']);
$router->post('/counters/{number}/open', [$counters, 'open']);
$router->post('/counters/{number}/close', [$counters, 'close']);

$tokens = new TokenController();
$router->post('/tokens', [$tokens, 'take']);
$router->post('/counters/{number}/call-next', [$tokens, 'callNext']);
$router->post('/counters/{number}/complete', [$tokens, 'complete']);
$router->post('/counters/{number}/no-show', [$tokens, 'noShow']);
