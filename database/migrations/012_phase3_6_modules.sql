ALTER TABLE cylinders
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'MANUAL' AFTER condition_code;

CREATE INDEX idx_cylinders_source ON cylinders(source);

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
    CONSTRAINT fk_cheques_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expense_categories_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO expense_categories(name,active)
VALUES ('Electricity',1),('Gas Bill',1),('Water',1),('Internet',1),('Rent',1),('Misc',1);

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

INSERT INTO permissions(code,name,module) VALUES
('receipts.view','View Receipts','receipts'),
('receipts.create','Create Receipts','receipts'),
('receipts.void','Void Receipts','receipts'),
('sales.void','Void Sales','sales'),
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
('reports.view','View Reports','reports'),
('users.view','View Users','users'),
('users.manage','Manage Users','users')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='ADMIN'
AND p.code IN (
'receipts.view','receipts.create','receipts.void',
'sales.void',
'purchases.view','purchases.create','purchases.void',
'payments.view','payments.create','payments.void',
'cheques.view','cheques.manage',
'expenses.view','expenses.create','expenses.void',
'reports.view','users.view','users.manage'
);
