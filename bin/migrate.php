<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Services\MigrationService;

$config = Config::load(dirname(__DIR__));
date_default_timezone_set($config['timezone']);
$db = DB::fromConfig($config);
$result = (new MigrationService($db, dirname(__DIR__)))->run();
foreach ($result['applied'] as $filename) echo "APPLY  {$filename}" . PHP_EOL;
foreach ($result['skipped'] as $filename) echo "SKIP   {$filename}" . PHP_EOL;
echo 'Migration complete.' . PHP_EOL;
