<?php
declare(strict_types=1);

use App\Auth\Session;
use App\Http\Router;
use App\Support\Env;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
Session::start();

header('Content-Type: application/json; charset=utf-8');
// Defense in depth for the API response itself - belt-and-braces alongside
// public/.htaccess, which covers the static frontend files under Apache but
// not PHP responses, and isn't applied at all by `php -S` (dev only).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$router = new Router();
require $root . '/src/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
