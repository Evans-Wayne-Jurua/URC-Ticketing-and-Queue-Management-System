<?php
declare(strict_types=1);

// Router for `php -S` (local dev only) - mimics what public/api/.htaccess
// does under Apache (XAMPP locally, Azure App Service in production).

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    $path = '/index.html';
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

$file = __DIR__ . $path;
if (is_file($file)) {
    return false; // let the built-in server serve it directly
}

http_response_code(404);
echo 'Not found';
