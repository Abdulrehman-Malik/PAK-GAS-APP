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
 INDEX idx_sales_date(txn_date), INDEX idx_sales_customer(customer_id), INDEX idx_sales_status(status)
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
 INDEX idx_sale_lines_sale(sale_id), INDEX idx_sale_lines_cylinder(cylinder_id)
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
 INDEX idx_ledger_party_date(party_id,entry_date), INDEX idx_ledger_doc(doc_type,doc_id)
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
 INDEX idx_receipts_party_date(party_id,receipt_date), INDEX idx_receipts_sale(sale_id)
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
 INDEX idx_cash_counter_date(counter_id,entry_date), INDEX idx_cash_doc(doc_type,doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_party_balance AS
SELECT p.id,p.code,p.name,p.party_type,p.allow_credit,p.credit_limit,p.opening_balance,
       p.opening_balance + COALESCE(SUM(le.debit-le.credit),0) AS balance
FROM parties p LEFT JOIN ledger_entries le ON le.party_id=p.id
GROUP BY p.id,p.code,p.name,p.party_type,p.allow_credit,p.credit_limit,p.opening_balance;