<?php
declare(strict_types=1);

use App\Http\Router;
use App\Support\Env;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');

header('Content-Type: application/json; charset=utf-8');

$router = new Router();
require $root . '/src/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
