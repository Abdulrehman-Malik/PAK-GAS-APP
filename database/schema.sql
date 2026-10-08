-- Pak Gas POS
-- Canonical clean-install database baseline.
-- The web installer uses the database selected by DB_NAME in .env.
-- Direct SQL import remains compatible with the default pak_gas database.

CREATE DATABASE IF NOT EXISTS pak_gas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE pak_gas;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP VIEW IF EXISTS v_shop_stock_summary;
DROP VIEW IF EXISTS v_cylinder_status;
DROP VIEW IF EXISTS v_party_balance;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS counters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    INDEX idx_counters_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    module VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_sequences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_type VARCHAR(50) NOT NULL,
    prefix VARCHAR(50) NOT NULL,
    last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_sequences_type_prefix (doc_type, prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS code_sequences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prefix VARCHAR(100) NOT NULL UNIQUE,
    last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    default_counter_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    force_password_change TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_counter FOREIGN KEY (default_counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    INDEX idx_users_role (role_id),
    INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_group VARCHAR(100) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_settings_group_key (setting_group, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_json LONGTEXT NULL,
    new_json LONGTEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_audit_entity (entity, entity_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_rate (username, ip, attempted_at),
    CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    party_type ENUM('CUSTOMER','SUPPLIER','REFEREE') NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    address VARCHAR(500) NULL,
    allow_credit TINYINT(1) NOT NULL DEFAULT 0,
    credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_parties_code_ci (code),
    INDEX idx_parties_type_active (party_type, active),
    INDEX idx_parties_name (name),
    CONSTRAINT fk_parties_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_parties_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinder_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    capacity_kg DECIMAL(10,3) NOT NULL,
    cylinder_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinder_groups_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_groups_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (capacity_kg > 0),
    CHECK (cylinder_price >= 0),
    INDEX idx_cylinder_groups_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    group_id BIGINT UNSIGNED NOT NULL,
    gas_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL DEFAULT 'SHOP',
    customer_id BIGINT UNSIGNED NULL,
    condition_code ENUM('GOOD','DAMAGED') NOT NULL DEFAULT 'GOOD',
    source VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinders_group FOREIGN KEY (group_id) REFERENCES cylinder_groups(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_customer FOREIGN KEY (customer_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (gas_kg >= 0),
    INDEX idx_cylinders_group (group_id),
    INDEX idx_cylinders_location (location),
    INDEX idx_cylinders_customer (customer_id),
    INDEX idx_cylinders_condition (condition_code),
    INDEX idx_cylinders_source (source),
    INDEX idx_cylinders_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    gas_rate DECIMAL(10,2) NOT NULL,
    cylinder_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_rates_group FOREIGN KEY (group_id) REFERENCES cylinder_groups(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (gas_rate >= 0),
    CHECK (cylinder_price >= 0),
    UNIQUE KEY uq_rates_group_date (group_id, effective_date),
    INDEX idx_rates_effective (group_id, effective_date, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_date DATE NOT NULL,
    source ENUM('MANUAL','IMPORT') NOT NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    CONSTRAINT fk_stock_batches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_batches_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_stock_batches_date (batch_date),
    INDEX idx_stock_batches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_type VARCHAR(50) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_ok INT UNSIGNED NOT NULL DEFAULT 0,
    rows_error INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('PREVIEW','COMMITTED','FAILED') NOT NULL DEFAULT 'PREVIEW',
    error_report_path VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    INDEX idx_import_batches_type_date (import_type, created_at),
    CONSTRAINT fk_import_batches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(50) NOT NULL UNIQUE,
    txn_date DATE NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    counter_id BIGINT UNSIGNED NULL,
    issue_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    cylinder_sale_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    return_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    received_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    void_reason VARCHAR(500) NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_sales_customer FOREIGN KEY(customer_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_voided_by FOREIGN KEY(voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_sales_date(txn_date),
    INDEX idx_sales_customer(customer_id),
    INDEX idx_sales_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    line_type ENUM('ISSUE','SELL_FILLED','SELL_EMPTY','RETURN') NOT NULL,
    cylinder_id BIGINT UNSIGNED NOT NULL,
    gas_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cylinder_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    issue_line_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_lines_sale FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sale_lines_cylinder FOREIGN KEY(cylinder_id) REFERENCES cylinders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sale_lines_issue FOREIGN KEY(issue_line_id) REFERENCES sale_lines(id) ON DELETE RESTRICT,
    INDEX idx_sale_lines_sale(sale_id),
    INDEX idx_sale_lines_cylinder(cylinder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(50) NOT NULL UNIQUE,
    supplier_id BIGINT UNSIGNED NOT NULL,
    supplier_invoice_no VARCHAR(100) NULL,
    purchase_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    void_reason VARCHAR(500) NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_purchases_supplier FOREIGN KEY(supplier_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchases_voided_by FOREIGN KEY(voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_purchases_date(purchase_date),
    INDEX idx_purchases_supplier(supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    line_type ENUM('GAS_FILL','NEW_CYLINDERS') NOT NULL,
    cylinder_id BIGINT UNSIGNED NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    gas_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    cylinder_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    gas_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_lines_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_lines_cylinder FOREIGN KEY(cylinder_id) REFERENCES cylinders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_lines_group FOREIGN KEY(group_id) REFERENCES cylinder_groups(id) ON DELETE RESTRICT,
    INDEX idx_purchase_lines_purchase(purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    party_id BIGINT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    doc_id BIGINT UNSIGNED NOT NULL,
    debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    narration VARCHAR(500) NULL,
    reversal_of BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_ledger_party FOREIGN KEY(party_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_reversal FOREIGN KEY(reversal_of) REFERENCES ledger_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_ledger_party_date(party_id,entry_date),
    INDEX idx_ledger_doc(doc_type,doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(50) NOT NULL UNIQUE,
    receipt_date DATE NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method ENUM('CASH','ONLINE','CHEQUE') NOT NULL DEFAULT 'CASH',
    counter_id BIGINT UNSIGNED NULL,
    source ENUM('MANUAL','POS') NOT NULL DEFAULT 'MANUAL',
    sale_id BIGINT UNSIGNED NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    narration VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_receipts_party FOREIGN KEY(party_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_sale FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_receipts_party_date(party_id,receipt_date),
    INDEX idx_receipts_sale(sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(50) NOT NULL UNIQUE,
    payment_date DATE NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method ENUM('CASH','ONLINE','CHEQUE') NOT NULL DEFAULT 'CASH',
    counter_id BIGINT UNSIGNED NULL,
    source ENUM('MANUAL','PURCHASE') NOT NULL DEFAULT 'MANUAL',
    purchase_id BIGINT UNSIGNED NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    narration VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_payments_party FOREIGN KEY(party_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_purchase FOREIGN KEY(purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_payments_party_date(party_id,payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS counter_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    counter_id BIGINT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_by BIGINT UNSIGNED NOT NULL,
    opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    closed_at DATETIME NULL,
    closed_by BIGINT UNSIGNED NULL,
    counted_cash DECIMAL(14,2) NULL,
    expected_cash DECIMAL(14,2) NULL,
    variance DECIMAL(14,2) NULL,
    CONSTRAINT fk_sessions_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sessions_opened_by FOREIGN KEY(opened_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sessions_closed_by FOREIGN KEY(closed_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_sessions_counter_open(counter_id,closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    counter_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    entry_date DATE NOT NULL,
    direction ENUM('IN','OUT') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    doc_type VARCHAR(50) NULL,
    doc_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cash_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cash_session FOREIGN KEY(session_id) REFERENCES counter_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cash_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_cash_counter_date(counter_id,entry_date),
    INDEX idx_cash_doc(doc_type,doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cheques (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('IN','OUT') NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    cheque_no VARCHAR(100) NOT NULL,
    bank VARCHAR(150) NULL,
    cheque_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('PENDING','CLEARED','BOUNCED') NOT NULL DEFAULT 'PENDING',
    cleared_date DATE NULL,
    receipt_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    bounced_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_cheques_direction_no (direction, cheque_no),
    INDEX idx_cheques_status_date (status, cheque_date),
    INDEX idx_cheques_party (party_id),
    CONSTRAINT fk_cheques_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expense_categories_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method ENUM('CASH','ONLINE','CHEQUE') NOT NULL DEFAULT 'CASH',
    counter_id BIGINT UNSIGNED NULL,
    reference_no VARCHAR(100) NULL,
    payee VARCHAR(150) NULL,
    notes VARCHAR(500) NULL,
    attachment VARCHAR(500) NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    void_reason VARCHAR(500) NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expenses_category FOREIGN KEY(category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_counter FOREIGN KEY(counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_voided_by FOREIGN KEY(voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_category (category_id),
    INDEX idx_expenses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinder_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cylinder_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(50) NOT NULL,
    before_gas_kg DECIMAL(10,3) NOT NULL,
    after_gas_kg DECIMAL(10,3) NOT NULL,
    from_location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL,
    to_location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    rate DECIMAL(10,2) NULL,
    source_document_type VARCHAR(50) NULL,
    source_document_id BIGINT UNSIGNED NULL,
    stock_batch_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinder_movements_cylinder FOREIGN KEY (cylinder_id) REFERENCES cylinders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_movements_customer FOREIGN KEY (customer_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_movements_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_movements_batch FOREIGN KEY (stock_batch_id) REFERENCES stock_batches(id) ON DELETE RESTRICT,
    INDEX idx_cylinder_movements_cylinder (cylinder_id, created_at),
    INDEX idx_cylinder_movements_source (source_document_type, source_document_id),
    INDEX idx_cylinder_movements_customer (customer_id),
    INDEX idx_cylinder_movements_batch (stock_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_cylinder_status AS
SELECT
    c.id,
    c.code,
    c.group_id,
    cg.code AS group_code,
    cg.name AS group_name,
    cg.capacity_kg,
    c.gas_kg,
    c.location,
    c.customer_id,
    c.condition_code,
    c.active,
    CASE
        WHEN c.location = 'CUSTOMER' THEN 'ISSUED'
        WHEN c.location = 'SOLD' THEN 'SOLD'
        WHEN c.gas_kg = cg.capacity_kg THEN 'FILLED'
        WHEN c.gas_kg > 0 THEN 'PARTIAL'
        ELSE 'EMPTY'
    END AS status
FROM cylinders c
INNER JOIN cylinder_groups cg ON cg.id = c.group_id;

CREATE OR REPLACE VIEW v_shop_stock_summary AS
SELECT
    c.group_id,
    cg.code AS group_code,
    cg.name AS group_name,
    cg.capacity_kg,
    COUNT(*) AS cylinder_count,
    COALESCE(SUM(c.gas_kg), 0) AS gas_kg
FROM cylinders c
INNER JOIN cylinder_groups cg ON cg.id = c.group_id
WHERE c.location = 'SHOP' AND c.condition_code = 'GOOD' AND c.active = 1
GROUP BY c.group_id, cg.code, cg.name, cg.capacity_kg;

CREATE OR REPLACE VIEW v_party_balance AS
SELECT
    p.id,
    p.code,
    p.name,
    p.party_type,
    p.allow_credit,
    p.credit_limit,
    p.opening_balance,
    p.opening_balance + COALESCE(SUM(le.debit - le.credit), 0) AS balance
FROM parties p
LEFT JOIN ledger_entries le ON le.party_id = p.id
GROUP BY p.id, p.code, p.name, p.party_type, p.allow_credit, p.credit_limit, p.opening_balance;

-- Static seed data. Admin credentials are created by bin/seed.php from .env.
INSERT INTO permissions(code,name,module) VALUES
('dashboard.view','View dashboard','dashboard'),
('settings.view','View settings','settings'),
('settings.manage','Manage settings','settings'),
('users.view','View users','users'),
('users.manage','Manage users','users'),
('opening_stock.view','View opening stock','opening_stock'),
('opening_stock.create','Create opening stock','opening_stock'),
('opening_stock.void','Void opening stock','opening_stock'),
('parties.view','View parties','parties'),
('parties.create','Create parties','parties'),
('cylinder_groups.view','View cylinder groups','cylinder_groups'),
('cylinder_groups.create','Create cylinder groups','cylinder_groups'),
('cylinders.view','View cylinders','cylinders'),
('cylinders.create','Create cylinders','cylinders'),
('rates.view','View rates','rates'),
('rates.create','Create rates','rates'),
('sales.view','View POS','sales'),
('sales.create','Create sales','sales'),
('sales.void','Void sales','sales'),
('counter.view','View Cash Counter','counter'),
('counter.open','Open Cash Counter','counter'),
('counter.close','Close Cash Counter','counter'),
('receipts.view','View Receipts','receipts'),
('receipts.create','Create Receipts','receipts'),
('receipts.void','Void Receipts','receipts'),
('purchases.view','View Purchases','purchases'),
('purchases.create','Create Purchases','purchases'),
('purchases.void','Void Purchases','purchases'),
('payments.view','View Payments','payments'),
('payments.create','Create Payments','payments'),
('payments.void','Void Payments','payments'),
('cheques.view','View Cheques','cheques'),
('cheques.manage','Manage Cheques','cheques'),
('expenses.view','View Expenses','expenses'),
('expenses.create','Create Expenses','expenses'),
('expenses.void','Void Expenses','expenses'),
('reports.view','View Reports','reports')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT INTO permissions(code,name,module) VALUES
('*','All permissions','system')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT INTO roles(code,name,active)
VALUES ('ADMIN','Administrator',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),active=1;

INSERT INTO counters(name,opening_cash,active)
VALUES ('Main Counter',0.00,1)
ON DUPLICATE KEY UPDATE active=1;

INSERT INTO doc_sequences(doc_type,prefix,last_value) VALUES
('SALE','SAL-',0),
('RECEIPT','RCT-',0),
('PURCHASE','PUR-',0),
('PAYMENT','PAY-',0)
ON DUPLICATE KEY UPDATE prefix=VALUES(prefix);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code='ADMIN';

INSERT INTO settings(setting_group,setting_key,setting_value) VALUES
('general','app_name','Pak Gas POS'),
('general','timezone','Asia/Karachi'),
('general','date_format','d-m-Y'),
('general','currency_symbol','PKR'),
('general','session_required','1'),
('sales','pos_transaction_types','GAS_SALE,EMPTY_CYLINDER_SALE,CYLINDER_RETURN'),
('sales','pos_default_transaction_type','GAS_SALE'),
('sales_credit','credit_limit_enforcement','BLOCK'),
('sales_credit','gas_decimals','3'),
('sales_credit','money_decimals','2'),
('sales_credit','default_payment_method','CASH'),
('sales_credit','rate_edit','1'),
('sales_credit','allow_advance','1'),
('code_generation','cylinder_code_mode','AUTO'),
('code_generation','cylinder_code_pattern','{GROUP}{CAP}-{SEQ}'),
('code_generation','cylinder_seq_width','6'),
('code_generation','group_code_mode','AUTO'),
('code_generation','group_code_prefix','G'),
('code_generation','group_seq_width','3'),
('tax','enabled','0'),
('tax','rate_percent','0.00'),
('tax','name','Sales Tax'),
('printing','paper_size','80MM'),
('cheques','cheque_ledger_posting','ON_CLEARANCE')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO expense_categories(name,active) VALUES
('Electricity',1),
('Gas Bill',1),
('Water',1),
('Internet',1),
('Rent',1),
('Misc',1)
ON DUPLICATE KEY UPDATE active=1;

INSERT INTO schema_migrations (migration, checksum, applied_at)
VALUES ('__SCHEMA_BASELINE__', SHA2('database/schema.sql baseline', 256), NOW())
ON DUPLICATE KEY UPDATE checksum = VALUES(checksum);

SET FOREIGN_KEY_CHECKS = 1;
