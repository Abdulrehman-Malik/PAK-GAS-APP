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
    ['sales.view', 'View POS', 'sales'], ['sales.create', 'Create sales', 'sales'], ['sales.void', 'Void sales', 'sales'],
    ['counter.view', 'View Cash Counter', 'counter'], ['counter.open', 'Open Cash Counter', 'counter'], ['counter.close', 'Close Cash Counter', 'counter'],
    ['receipts.view', 'View Receipts', 'receipts'], ['receipts.create', 'Create Receipts', 'receipts'], ['receipts.void', 'Void Receipts', 'receipts'],
    ['purchases.view', 'View Purchases', 'purchases'], ['purchases.create', 'Create Purchases', 'purchases'], ['purchases.void', 'Void Purchases', 'purchases'],
    ['payments.view', 'View Payments', 'payments'], ['payments.create', 'Create Payments', 'payments'], ['payments.void', 'Void Payments', 'payments'],
    ['cheques.view', 'View Cheques', 'cheques'], ['cheques.manage', 'Manage Cheques', 'cheques'],
    ['expenses.view', 'View Expenses', 'expenses'], ['expenses.create', 'Create Expenses', 'expenses'], ['expenses.void', 'Void Expenses', 'expenses'],
    ['reports.view', 'View Reports', 'reports'],
    ['users.view', 'View Users', 'users'], ['users.manage', 'Manage Users', 'users'],
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
    ['general', 'currency_symbol', 'PKR'],
    ['general', 'session_required', '1'],
    ['sales', 'pos_transaction_types', 'GAS_SALE,EMPTY_CYLINDER_SALE,CYLINDER_RETURN'],
    ['sales', 'pos_default_transaction_type', 'GAS_SALE'],
    ['sales_credit', 'credit_limit_enforcement', 'BLOCK'],
    ['sales_credit', 'gas_decimals', '3'],
    ['sales_credit', 'money_decimals', '2'],
    ['sales_credit', 'default_payment_method', 'CASH'],
    ['sales_credit', 'rate_edit', '1'],
    ['sales_credit', 'allow_advance', '1'],
    ['code_generation', 'cylinder_code_mode', 'AUTO'],
    ['code_generation', 'cylinder_code_pattern', '{GROUP}{CAP}-{SEQ}'],
    ['code_generation', 'cylinder_seq_width', '6'],
    ['code_generation', 'group_code_mode', 'AUTO'],
    ['code_generation', 'group_code_prefix', 'G'],
    ['code_generation', 'group_seq_width', '3'],
    ['tax', 'enabled', '0'],
    ['tax', 'rate_percent', '0.00'],
    ['tax', 'name', 'Sales Tax'],
    ['printing', 'paper_size', '80MM'],
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
