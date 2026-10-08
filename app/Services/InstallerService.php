<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class InstallerService
{
    private const REQUIRED_EXTENSIONS = [
        'pdo' => 'PDO',
        'pdo_mysql' => 'PDO MySQL',
        'mbstring' => 'Multibyte String (mbstring)',
        'fileinfo' => 'File Information (fileinfo)',
        'zip' => 'ZIP / Excel support (zip)',
        'xml' => 'XML / Excel support (xml)',
        'bcmath' => 'BCMath',
    ];

    private const CORE_TABLES = [
        'roles',
        'permissions',
        'role_permissions',
        'counters',
        'users',
        'settings',
        'parties',
        'cylinder_groups',
        'cylinders',
        'ledger_entries',
        'sales',
        'purchases',
        'cash_entries',
    ];

    private const BASELINE_MIGRATION = '__SCHEMA_BASELINE__';

    public function __construct(private readonly array $config)
    {
    }

    public function requirements(): array
    {
        $checks = [
            'php' => [
                'label' => 'PHP >= 8.1',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'actual' => PHP_VERSION,
            ],
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension => $label) {
            $checks[$extension] = [
                'label' => $label,
                'ok' => extension_loaded($extension),
                'actual' => extension_loaded($extension) ? 'Enabled' : 'Not enabled',
            ];
        }

        return $checks;
    }

    public function status(): array
    {
        $requirements = $this->requirements();
        $missingRequirements = [];

        foreach ($requirements as $key => $check) {
            if (!$check['ok']) {
                $missingRequirements[$key] = $check;
            }
        }

        $status = [
            'requirements' => $requirements,
            'missing_requirements' => $missingRequirements,
            'env_exists' => is_file($this->config['base_path'] . '/.env'),
            'database_exists' => false,
            'schema_installed' => false,
            'pending_migrations' => [],
            'installed' => false,
            'db_error' => null,
            'db' => [
                'host' => (string) $this->config['db']['host'],
                'port' => (int) $this->config['db']['port'],
                'name' => (string) $this->config['db']['name'],
                'user' => (string) $this->config['db']['user'],
                'password_configured' => (string) $this->config['db']['pass'] !== '',
            ],
            'admin_username' => (string) ($this->config['seed_admin_username'] ?? 'admin'),
        ];

        if (!$status['env_exists'] || $missingRequirements !== []) {
            return $status;
        }

        try {
            $server = $this->serverPdo();
            $status['database_exists'] = $this->databaseExists($server);

            if (!$status['database_exists']) {
                return $status;
            }

            $db = $this->databasePdo();
            $status['schema_installed'] = $this->hasCoreSchema($db);

            if (!$status['schema_installed']) {
                return $status;
            }

            $this->ensureMigrationTable($db);
            $status['installed'] = true;
            $status['pending_migrations'] = $this->pendingMigrations($db);
        } catch (\Throwable $exception) {
            $status['db_error'] = $exception->getMessage();
        }

        return $status;
    }

    public function install(): array
    {
        $status = $this->status();

        if (!$status['env_exists']) {
            throw new \RuntimeException(
                'The .env file was not found. Copy .env.example to .env and set the database details.'
            );
        }

        if ($status['missing_requirements'] !== []) {
            $names = array_map(
                static fn (array $check): string => $check['label'],
                $status['missing_requirements']
            );
            throw new \RuntimeException(
                'Install cannot continue. Enable: ' . implode(', ', $names) . '.'
            );
        }

        $password = (string) ($this->config['seed_admin_password'] ?? '');
        if ($password === '') {
            throw new \RuntimeException(
                'SEED_ADMIN_PASSWORD is missing in .env. Set it before installation.'
            );
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException(
                'SEED_ADMIN_PASSWORD must be at least 8 characters.'
            );
        }

        $this->validateIdentifier((string) $this->config['db']['name']);

        $server = $this->serverPdo();
        $quote = chr(96);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $quote . $this->config['db']['name'] . $quote .
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $db = $this->databasePdo();

        if (!$this->hasCoreSchema($db)) {
            $schemaPath = $this->config['base_path'] . '/database/schema.sql';
            if (!is_file($schemaPath)) {
                throw new \RuntimeException('database/schema.sql was not found.');
            }

            $sql = file_get_contents($schemaPath);
            if ($sql === false || trim($sql) === '') {
                throw new \RuntimeException('database/schema.sql could not be read.');
            }

            $sql = preg_replace(
                '/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+[A-Za-z0-9_$-]+\s+CHARACTER\s+SET\s+utf8mb4\s+COLLATE\s+utf8mb4_unicode_ci\s*;/i',
                '',
                $sql
            ) ?? $sql;
            $sql = preg_replace(
                '/USE\s+[A-Za-z0-9_$-]+\s*;/i',
                '',
                $sql
            ) ?? $sql;

            try {
                $db->exec($sql);
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    'Database initialization failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        $this->ensureMigrationTable($db);
        $this->markBaseline($db);
        $this->seedAdmin($db);

        $applied = $this->runPendingMigrations($db);

        return [
            'migrations' => $applied,
            'admin_username' => (string) ($this->config['seed_admin_username'] ?? 'admin'),
        ];
    }

    public function runPendingMigrations(?PDO $db = null): array
    {
        $db ??= $this->databasePdo();
        $this->ensureMigrationTable($db);

        $lock = (int) (
            $db->query("SELECT GET_LOCK('pak_gas_schema_migrations', 30)")->fetchColumn() ?? 0
        );

        if ($lock !== 1) {
            throw new \RuntimeException(
                'Unable to obtain the database update lock. Please try again.'
            );
        }

        try {
            $appliedRows = $db->query(
                'SELECT migration, checksum FROM schema_migrations ORDER BY migration'
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            $files = glob($this->config['base_path'] . '/database/migrations/*.sql') ?: [];
            sort($files, SORT_NATURAL | SORT_FLAG_CASE);

            $applied = [];

            foreach ($files as $file) {
                $filename = basename($file);
                if (!preg_match('/^\d+_[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/i', $filename)) {
                    continue;
                }

                $checksum = hash_file('sha256', $file);
                if ($checksum === false) {
                    throw new \RuntimeException(
                        'Unable to calculate checksum for migration ' . $filename . '.'
                    );
                }

                if (isset($appliedRows[$filename])) {
                    if (!hash_equals((string) $appliedRows[$filename], $checksum)) {
                        throw new \RuntimeException(
                            'Migration ' . $filename .
                            ' was changed after it was applied. Restore the original file or create a new migration.'
                        );
                    }
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') {
                    throw new \RuntimeException(
                        'Migration ' . $filename . ' is empty or unreadable.'
                    );
                }

                $db->beginTransaction();

                try {
                    $db->exec($sql);

                    $statement = $db->prepare(
                        'INSERT INTO schema_migrations (migration, checksum, applied_at)
                         VALUES (:migration, :checksum, NOW())'
                    );
                    $statement->execute([
                        'migration' => $filename,
                        'checksum' => $checksum,
                    ]);

                    $db->commit();
                } catch (\Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    throw new \RuntimeException(
                        'Migration ' . $filename . ' failed: ' . $exception->getMessage(),
                        0,
                        $exception
                    );
                }

                $applied[] = $filename;
            }

            return $applied;
        } finally {
            $db->query("SELECT RELEASE_LOCK('pak_gas_schema_migrations')");
        }
    }

    public function seedAdmin(?PDO $db = null): void
    {
        $db ??= $this->databasePdo();
        $this->ensureMigrationTable($db);

        $role = $db->query(
            "SELECT id FROM roles WHERE code='ADMIN' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $counter = $db->query(
            "SELECT id FROM counters WHERE name='Main Counter' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if (!$role || !$counter) {
            throw new \RuntimeException(
                'The database baseline is incomplete. Re-run the installation against a clean database.'
            );
        }

        $username = trim((string) ($this->config['seed_admin_username'] ?? 'admin'));
        $password = (string) ($this->config['seed_admin_password'] ?? '');

        if ($username === '' || strlen($username) > 100) {
            throw new \RuntimeException('SEED_ADMIN_USERNAME is invalid.');
        }
        if ($password === '') {
            throw new \RuntimeException('SEED_ADMIN_PASSWORD is missing in .env.');
        }

        $statement = $db->prepare(
            'INSERT INTO users
                (username, full_name, password_hash, role_id, default_counter_id, force_password_change, active)
             VALUES
                (:username, :full_name, :password_hash, :role_id, :counter_id, 1, 1)
             ON DUPLICATE KEY UPDATE
                full_name=VALUES(full_name),
                password_hash=VALUES(password_hash),
                role_id=VALUES(role_id),
                default_counter_id=VALUES(counter_id),
                force_password_change=1,
                active=1'
        );

        $statement->execute([
            'username' => $username,
            'full_name' => 'System Administrator',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $role['id'],
            'counter_id' => $counter['id'],
        ]);
    }

    private function serverPdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $this->config['db']['host'],
            $this->config['db']['port']
        );

        return new PDO(
            $dsn,
            $this->config['db']['user'],
            $this->config['db']['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
    }

    private function databasePdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['db']['host'],
            $this->config['db']['port'],
            $this->config['db']['name']
        );

        return new PDO(
            $dsn,
            $this->config['db']['user'],
            $this->config['db']['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
    }

    private function databaseExists(PDO $server): bool
    {
        $statement = $server->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata
             WHERE schema_name = :name'
        );
        $statement->execute(['name' => $this->config['db']['name']]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function hasCoreSchema(PDO $db): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::CORE_TABLES), '?'));
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = 'BASE TABLE'
               AND table_name IN ($placeholders)"
        );
        $statement->execute(self::CORE_TABLES);

        return (int) $statement->fetchColumn() === count(self::CORE_TABLES);
    }

    private function ensureMigrationTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function markBaseline(PDO $db): void
    {
        $statement = $db->prepare(
            'INSERT INTO schema_migrations (migration, checksum, applied_at)
             VALUES (:migration, :checksum, NOW())
             ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
        );

        $statement->execute([
            'migration' => self::BASELINE_MIGRATION,
            'checksum' => hash('sha256', 'database/schema.sql baseline'),
        ]);
    }

    private function pendingMigrations(PDO $db): array
    {
        $applied = $db->query(
            'SELECT migration, checksum FROM schema_migrations'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob($this->config['base_path'] . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        $pending = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (!preg_match('/^\d+_[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/i', $filename)) {
                continue;
            }

            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                continue;
            }

            if (!isset($applied[$filename])) {
                $pending[] = [
                    'name' => $filename,
                    'checksum' => $checksum,
                ];
                continue;
            }

            if (!hash_equals((string) $applied[$filename], $checksum)) {
                $pending[] = [
                    'name' => $filename,
                    'checksum' => $checksum,
                    'changed' => true,
                ];
            }
        }

        return $pending;
    }

    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
            throw new \RuntimeException('Invalid DB_NAME configuration.');
        }
    }
}
