<?php
declare(strict_types=1);

// One-off dev utility to create the first clerk/supervisor accounts, since
// there's no admin UI yet to do it through. CLI only, run once:
//   php database/seed_users.php
// Re-running it just resets these accounts' passwords (ON DUPLICATE KEY).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Support\Env;

Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();

$users = [
    ['email' => 'clerk@urc.test',      'password' => 'ClerkPass123!',      'role' => 'clerk'],
    ['email' => 'supervisor@urc.test', 'password' => 'SupervisorPass123!', 'role' => 'supervisor'],
];

$stmt = $pdo->prepare(
    'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
);

foreach ($users as $u) {
    $stmt->execute([
        'email' => $u['email'],
        'hash' => password_hash($u['password'], PASSWORD_DEFAULT),
        'role' => $u['role'],
    ]);
    echo "seeded: {$u['email']} ({$u['role']})\n";
}
