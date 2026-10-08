ALTER TABLE cylinders
    ADD COLUMN source ENUM('OPENING','MANUAL','PURCHASE') NOT NULL DEFAULT 'MANUAL' AFTER condition_code;

ALTER TABLE cylinders
    ADD INDEX idx_cylinders_source (source);

ALTER TABLE receipts
    ADD COLUMN cheque_id BIGINT UNSIGNED NULL AFTER counter_id;

ALTER TABLE payments
    ADD COLUMN cheque_id BIGINT UNSIGNED NULL AFTER counter_id;

CREATE TABLE IF NOT EXISTS cheques (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('IN','OUT') NOT NULL,
    party_id BIGINT UNSIGNED NULL,
    cheque_no VARCHAR(100) NOT NULL,
    bank VARCHAR(150) NULL,
    cheque_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('PENDING','CLEARED','BOUNCED') NOT NULL DEFAULT 'PENDING',
    cleared_date DATE NULL,
    bounce_reason VARCHAR(500) NULL,
    receipt_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_cheques_direction_no (direction, cheque_no),
    INDEX idx_cheques_status_date (status, cheque_date),
    INDEX idx_cheques_party (party_id),
    CONSTRAINT fk_cheques_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheques_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE receipts
    ADD CONSTRAINT fk_receipts_cheque FOREIGN KEY (cheque_id) REFERENCES cheques(id) ON DELETE RESTRICT;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_cheque FOREIGN KEY (cheque_id) REFERENCES cheques(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS expense_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expense_categories_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expense_categories_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    method ENUM('CASH','ONLINE','CHEQUE') NOT NULL DEFAULT 'CASH',
    counter_id BIGINT UNSIGNED NULL,
    cheque_id BIGINT UNSIGNED NULL,
    reference_no VARCHAR(100) NULL,
    payee VARCHAR(150) NULL,
    notes VARCHAR(500) NULL,
    attachment_path VARCHAR(500) NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    void_reason VARCHAR(500) NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_counter FOREIGN KEY (counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_cheque FOREIGN KEY (cheque_id) REFERENCES cheques(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_expenses_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_category (category_id),
    INDEX idx_expenses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transfer_date DATE NOT NULL,
    from_counter_id BIGINT UNSIGNED NOT NULL,
    to_counter_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cash_transfers_from_counter FOREIGN KEY (from_counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cash_transfers_to_counter FOREIGN KEY (to_counter_id) REFERENCES counters(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cash_transfers_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_cash_transfers_date (transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE counter_sessions
    ADD COLUMN notes VARCHAR(500) NULL AFTER variance;

INSERT INTO expense_categories (name, active)
VALUES
    ('Electricity',1),
    ('Gas Bill',1),
    ('Water',1),
    ('Internet',1),
    ('Rent',1),
    ('Miscellaneous',1)
ON DUPLICATE KEY UPDATE active=VALUES(active);

INSERT INTO settings(setting_group, setting_key, setting_value)
VALUES
    ('sales_credit','credit_limit_enforcement','BLOCK'),
    ('sales_credit','default_payment_method','CASH'),
    ('sales_credit','allow_rate_edit','1'),
    ('sales_credit','allow_advance','1'),
    ('tax','tax_name','Tax'),
    ('tax','applies_to_gas','1'),
    ('printing','header_text',''),
    ('printing','footer_text',''),
    ('cheques','cheque_ledger_posting','ON_CLEARANCE')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
