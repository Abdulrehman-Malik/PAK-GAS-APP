<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\DB;

$config = Config::load(dirname(__DIR__));
date_default_timezone_set($config['timezone']);

$db = DB::fromConfig($config);

$schemaCheck = $db->fetchOne("SELECT
    (SELECT COUNT(*) FROM roles WHERE code='ADMIN') AS admin_role,
    (SELECT COUNT(*) FROM counters WHERE name='Main Counter') AS main_counter");

if (!$schemaCheck || (int) $schemaCheck['admin_role'] < 1 || (int) $schemaCheck['main_counter'] < 1) {
    throw new RuntimeException(
        'Database is not initialized. Import database/schema.sql before running bin/seed.php.'
    );
}

$role = $db->fetchOne('SELECT id FROM roles WHERE code=:code', ['code' => 'ADMIN']);
$counter = $db->fetchOne('SELECT id FROM counters WHERE name=:name', ['name' => 'Main Counter']);

$username = getenv('SEED_ADMIN_USERNAME') ?: 'admin';
$password = getenv('SEED_ADMIN_PASSWORD');

if ($password === false || $password === '') {
    throw new RuntimeException(
        'Set SEED_ADMIN_PASSWORD in .env before running bin/seed.php.'
    );
}

$db->execute(
    'INSERT INTO users
        (username, full_name, password_hash, role_id, default_counter_id, force_password_change, active)
     VALUES
        (:username, :full_name, :password_hash, :role_id, :counter_id, 1, 1)
     ON DUPLICATE KEY UPDATE
        full_name=VALUES(full_name),
        password_hash=VALUES(password_hash),
        role_id=VALUES(role_id),
        default_counter_id=VALUES(default_counter_id),
        force_password_change=1,
        active=1',
    [
        'username' => $username,
        'full_name' => 'System Administrator',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $role['id'],
        'counter_id' => $counter['id'],
    ]
);

echo "Admin seed complete. Username: {$username}" . PHP_EOL;
