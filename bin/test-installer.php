<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Services\InstallerService;

$basePath = dirname(__DIR__);
$config = [
    'base_path' => $basePath,
    'app_env' => 'test',
    'app_url' => 'http://localhost',
    'app_name' => 'Pak Gas POS',
    'timezone' => 'Asia/Karachi',
    'db' => [
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
        'name' => getenv('TEST_DB_NAME') ?: 'pak_gas',
        'user' => getenv('TEST_DB_USER') ?: 'root',
        'pass' => getenv('TEST_DB_PASS') ?: '',
    ],
    'session_timeout' => 1800,
    'session_secure_cookie' => false,
    'seed_admin_username' => 'ci_admin',
    'seed_admin_password' => 'CiInstallerPassword123!',
];

$installer = new InstallerService($config);
$status = $installer->status();

if (!$status['installed']) {
    throw new RuntimeException(
        'Installer smoke test requires an initialized schema: ' . ($status['db_error'] ?? 'schema not detected')
    );
}

$pending = $status['pending_migrations'];
if (count($pending) !== 1 || ($pending[0]['name'] ?? '') !== '999_ci_installer_smoke.sql') {
    throw new RuntimeException('Expected exactly one CI migration to be pending.');
}

$applied = $installer->runPendingMigrations();
if ($applied !== ['999_ci_installer_smoke.sql']) {
    throw new RuntimeException('CI migration was not applied exactly once.');
}

$secondRun = $installer->runPendingMigrations();
if ($secondRun !== []) {
    throw new RuntimeException('Migration runner is not idempotent.');
}

$statusAfter = $installer->status();
if ($statusAfter['pending_migrations'] !== []) {
    throw new RuntimeException('Migration queue is not empty after applying the CI migration.');
}

echo "Installer/migration smoke test passed." . PHP_EOL;
