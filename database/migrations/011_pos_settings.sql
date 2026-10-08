INSERT INTO settings(setting_group, setting_key, setting_value)
VALUES ('sales', 'pos_default_transaction_type', 'GAS_SALE')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
