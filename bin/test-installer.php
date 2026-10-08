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

$status = null;
for ($attempt = 1; $attempt <= 30; $attempt++) {
    $status = $installer->status();
    if (($status['db_error'] ?? null) === null) {
        break;
    }
    usleep(500000);
}

if (!is_array($status) || ($status['db_error'] ?? null) !== null) {
    throw new RuntimeException(
        'Installer smoke test could not connect to the test database: ' .
        (($status['db_error'] ?? null) ?: 'unknown error')
    );
}

if (!$status['installed']) {
    $result = $installer->install();

    if (($result['admin_username'] ?? '') !== 'ci_admin') {
        throw new RuntimeException('Installer did not create the expected administrator.');
    }

    $status = $installer->status();
    if (!$status['installed'] || ($status['pending_migrations'] ?? []) !== []) {
        throw new RuntimeException('Fresh installation did not finish cleanly.');
    }
}

$migrationPath = $basePath . '/database/migrations/999_ci_installer_smoke.sql';
$migrationSql = "CREATE TABLE IF NOT EXISTS ci_installer_smoke (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;";
if (file_put_contents($migrationPath, $migrationSql) === false) {
    throw new RuntimeException('Unable to create the CI migration fixture.');
}

try {
    $pending = $installer->status()['pending_migrations'] ?? [];
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
} finally {
    @unlink($migrationPath);
}
