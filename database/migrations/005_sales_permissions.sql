INSERT INTO permissions(code,name,module) VALUES
('sales.view','View POS','sales'),('sales.create','Create sales','sales'),('sales.void','Void sales','sales')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='ADMIN' AND p.code IN ('sales.view','sales.create','sales.void');

INSERT INTO settings(setting_group,setting_key,setting_value)
VALUES('sales','pos_transaction_types','GAS_SALE,EMPTY_CYLINDER_SALE')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);