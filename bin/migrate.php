<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\DB;

$config = Config::load(dirname(__DIR__));
date_default_timezone_set($config['timezone']);
$db = DB::fromConfig($config);
$pdo = $db->pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_NATURAL);

foreach ($files as $file) {
    $filename = basename($file);

    if ($db->fetchOne('SELECT id FROM migrations WHERE filename = :filename', ['filename' => $filename]) !== null) {
        echo "SKIP  {$filename}" . PHP_EOL;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read migration: {$filename}");
    }

    echo "APPLY {$filename}" . PHP_EOL;
    $db->transaction(
        static function (DB $database) use ($sql, $filename): void {
            $database->pdo()->exec($sql);
            $database->execute(
                'INSERT INTO migrations (filename) VALUES (:filename)',
                ['filename' => $filename]
            );
        }
    );
}

echo 'Migration complete.' . PHP_EOL;
