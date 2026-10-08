INSERT INTO settings(setting_group,setting_key,setting_value) VALUES
('sales_credit','credit_limit_enforcement','BLOCK'),
('sales_credit','rate_edit','1'),
('sales_credit','allow_advance','1'),
('cheques','cheque_ledger_posting','ON_CLEARANCE'),
('tax','name','Sales Tax')
ON DUPLICATE KEY UPDATE setting_value=IF(setting_value='',VALUES(setting_value),setting_value);
