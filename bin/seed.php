<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\DB;

$config = Config::load(dirname(__DIR__));
date_default_timezone_set($config['timezone']);
$db = DB::fromConfig($config);

$permissions = [
    ['dashboard.view', 'View dashboard', 'dashboard'],
    ['settings.view', 'View settings', 'settings'],
    ['settings.manage', 'Manage settings', 'settings'],
    ['users.view', 'View users', 'users'],
    ['users.manage', 'Manage users', 'users'],
    ['opening_stock.view', 'View opening stock', 'opening_stock'],
    ['opening_stock.create', 'Create opening stock', 'opening_stock'],
    ['opening_stock.void', 'Void opening stock', 'opening_stock'],
    ['parties.view', 'View parties', 'parties'],
    ['parties.create', 'Create parties', 'parties'],
    ['cylinder_groups.view', 'View cylinder groups', 'cylinder_groups'],
    ['cylinder_groups.create', 'Create cylinder groups', 'cylinder_groups'],
    ['cylinders.view', 'View cylinders', 'cylinders'],
    ['cylinders.create', 'Create cylinders', 'cylinders'],
    ['rates.view', 'View rates', 'rates'],
    ['rates.create', 'Create rates', 'rates'],
];

foreach ($permissions as [$code, $name, $module]) {
    $db->execute(
        'INSERT INTO permissions (code, name, module) VALUES (:code, :name, :module)
         ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module)',
        ['code' => $code, 'name' => $name, 'module' => $module]
    );
}

$db->execute(
    'INSERT INTO permissions (code, name, module) VALUES ("*", "All permissions", "system")
     ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module)'
);

$db->execute(
    'INSERT INTO roles (code, name) VALUES ("ADMIN", "Administrator")
     ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1'
);
$role = $db->fetchOne('SELECT id FROM roles WHERE code = "ADMIN"');

$db->execute(
    'INSERT INTO counters (name, opening_cash) VALUES ("Main Counter", 0)
     ON DUPLICATE KEY UPDATE active = 1'
);
$counter = $db->fetchOne('SELECT id FROM counters WHERE name = "Main Counter"');

foreach ($db->fetchAll('SELECT id FROM permissions') as $permission) {
    $db->execute(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
        ['role_id' => $role['id'], 'permission_id' => $permission['id']]
    );
}

$username = getenv('SEED_ADMIN_USERNAME') ?: 'admin';
$password = getenv('SEED_ADMIN_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('Set SEED_ADMIN_PASSWORD in .env before running seed.php.');
}

$db->execute(
    'INSERT INTO users (username, full_name, password_hash, role_id, default_counter_id, force_password_change)
     VALUES (:username, :full_name, :password_hash, :role_id, :counter_id, 1)
     ON DUPLICATE KEY UPDATE
       full_name = VALUES(full_name),
       role_id = VALUES(role_id),
       default_counter_id = VALUES(default_counter_id),
       active = 1',
    [
        'username' => $username,
        'full_name' => 'System Administrator',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $role['id'],
        'counter_id' => $counter['id'],
    ]
);

$settings = [
    ['general', 'app_name', $config['app_name']],
    ['general', 'timezone', $config['timezone']],
    ['general', 'date_format', 'd-m-Y'],
    ['general', 'session_required', '1'],
    ['sales_credit', 'credit_enforcement', 'BLOCK'],
    ['sales_credit', 'credit_limit_enforcement', 'BLOCK'],
    ['sales_credit', 'default_payment_method', 'CASH'],
    ['sales_credit', 'allow_rate_edit', '1'],
    ['sales_credit', 'allow_advance', '1'],
    ['sales_credit', 'default_customer_type', 'CUSTOMER'],
    ['sales_credit', 'gas_decimals', '3'],
    ['sales_credit', 'money_decimals', '2'],
    ['code_generation', 'cylinder_code_mode', 'AUTO'],
    ['code_generation', 'cylinder_code_pattern', '{GROUP}{CAP}-{SEQ:6}'],
    ['code_generation', 'cylinder_seq_width', '6'],
    ['code_generation', 'group_code_mode', 'AUTO'],
    ['code_generation', 'group_code_prefix', 'G'],
    ['code_generation', 'group_code_width', '3'],
    ['tax', 'enabled', '0'],
    ['tax', 'rate_percent', '0.00'],
    ['printing', 'paper_size', '80MM'],
    ['cheques', 'posting_mode', 'ON_CLEARANCE'],
    ['cheques', 'cheque_ledger_posting', 'ON_CLEARANCE'],
];

foreach ($settings as [$group, $key, $value]) {
    $db->execute(
        'INSERT INTO settings (setting_group, setting_key, setting_value)
         VALUES (:setting_group, :setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['setting_group' => $group, 'setting_key' => $key, 'setting_value' => $value]
    );
}

echo "Seed complete. Admin username: {$username}" . PHP_EOL;
