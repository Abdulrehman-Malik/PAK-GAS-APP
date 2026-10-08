INSERT INTO settings(setting_group,setting_key,setting_value) VALUES
('code_generation','cylinder_code_pattern','{GROUP}{CAP}-{SEQ}'),
('code_generation','cylinder_seq_width','6'),
('code_generation','group_code_mode','AUTO'),
('code_generation','group_code_prefix','G'),
('code_generation','group_seq_width','3'),
('sales_credit','default_payment_method','CASH'),
('sales_credit','rate_edit','1'),
('sales_credit','allow_advance','1'),
('general','currency_symbol','PKR')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
