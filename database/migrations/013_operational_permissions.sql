INSERT INTO permissions(code,name,module) VALUES
('receipts.view','View Receipts','receipts'),
('receipts.create','Create Receipts','receipts'),
('receipts.void','Void Receipts','receipts'),
('cheques.view','View Cheques','cheques'),
('cheques.manage','Manage Cheques','cheques'),
('expenses.view','View Expenses','expenses'),
('expenses.create','Create Expenses','expenses'),
('expenses.void','Void Expenses','expenses'),
('reports.view','View Reports','reports'),
('reports.export','Export Reports','reports'),
('users.view','View Users','users'),
('users.manage','Manage Users','users')
ON DUPLICATE KEY UPDATE name=VALUES(name),module=VALUES(module);

INSERT INTO roles(code,name,active) VALUES
('CASHIER','Cashier',1),
('MANAGER','Manager',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),active=1;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p
WHERE r.code='CASHIER'
  AND p.code IN (
    'dashboard.view','sales.view','sales.create',
    'receipts.view','receipts.create',
    'counter.view','counter.open','counter.close',
    'cylinders.view','parties.view','reports.view'
  );

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p
WHERE r.code='MANAGER'
  AND p.code IN (
    'dashboard.view','settings.view',
    'parties.view','parties.create',
    'cylinder_groups.view','cylinder_groups.create',
    'cylinders.view','cylinders.create',
    'rates.view','rates.create',
    'opening_stock.view','opening_stock.create','opening_stock.void',
    'sales.view','sales.create','sales.void',
    'receipts.view','receipts.create','receipts.void',
    'purchases.view','purchases.create','purchases.void',
    'payments.view','payments.create','payments.void',
    'counter.view','counter.open','counter.close',
    'cheques.view','cheques.manage',
    'expenses.view','expenses.create','expenses.void',
    'reports.view','reports.export'
  );

INSERT IGNORE INTO settings(setting_group,setting_key,setting_value)
VALUES
('sales_credit','credit_limit_enforcement','BLOCK'),
('sales_credit','default_payment_method','CASH'),
('sales_credit','allow_rate_edit','1'),
('sales_credit','allow_advance','1');
