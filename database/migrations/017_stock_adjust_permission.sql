INSERT INTO permissions(code,name,module) VALUES
('cylinders.adjust','Adjust Cylinder Stock','cylinders')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p
WHERE r.code IN ('ADMIN','MANAGER') AND p.code='cylinders.adjust';
