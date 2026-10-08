INSERT INTO permissions(code,name,module) VALUES
('counter.view','View Cash Counter','counter'),('counter.open','Open Cash Counter','counter'),('counter.close','Close Cash Counter','counter')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='ADMIN' AND p.code IN ('counter.view','counter.open','counter.close');