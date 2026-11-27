<?php
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Http\Router;

/** @var Router $router */
$router->get('/health', [new HealthController(), 'show']);
