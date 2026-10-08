INSERT INTO permissions(code,name,module) VALUES
('purchases.view','View Purchases','purchases'),('purchases.create','Create Purchases','purchases'),('purchases.void','Void Purchases','purchases'),
('payments.view','View Payments','payments'),('payments.create','Create Payments','payments'),('payments.void','Void Payments','payments')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='ADMIN' AND p.code IN ('purchases.view','purchases.create','purchases.void','payments.view','payments.create','payments.void');