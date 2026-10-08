ALTER TABLE expenses
    ADD COLUMN doc_no VARCHAR(50) NULL AFTER id;

ALTER TABLE expenses
    ADD UNIQUE KEY uq_expenses_doc_no (doc_no);

INSERT INTO permissions(code,name,module) VALUES
('counter.adjust','Manual Cash In/Out and Transfers','counter')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p
WHERE r.code IN ('CASHIER','MANAGER') AND p.code='counter.adjust';
