<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Http\Router;

/** @var Router $router */
$router->get('/health', [new HealthController(), 'show']);

$auth = new AuthController();
$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/logout', [$auth, 'logout']);
$router->get('/auth/me', [$auth, 'me']);
