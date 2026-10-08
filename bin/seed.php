<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Services\InstallerService;

$config = Config::load(dirname(__DIR__));
date_default_timezone_set($config['timezone']);

$installer = new InstallerService($config);
$status = $installer->status();

if (!$status['installed']) {
    throw new RuntimeException(
        'Database is not initialized. Open the application installation page first.'
    );
}

$installer->runPendingMigrations();
$installer->seedAdmin();

echo 'Admin seed complete. Username: ' . $config['seed_admin_username'] . PHP_EOL;
