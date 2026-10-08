INSERT INTO settings(setting_group,setting_key,setting_value) VALUES
('code_generation','cylinder_code_pattern','{GROUP}{CAP}-{SEQ:6}'),
('code_generation','cylinder_seq_width','6'),
('code_generation','group_code_mode','AUTO'),
('code_generation','group_code_prefix','G'),
('code_generation','group_code_width','3'),
('sales_credit','credit_limit_enforcement','BLOCK'),
('sales_credit','default_payment_method','CASH'),
('sales_credit','allow_rate_edit','1'),
('sales_credit','allow_advance','1'),
('sales_credit','gas_decimals','3'),
('sales_credit','money_decimals','2'),
('tax','enabled','0'),
('tax','rate_percent','0.00'),
('cheques','cheque_ledger_posting','ON_CLEARANCE')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
