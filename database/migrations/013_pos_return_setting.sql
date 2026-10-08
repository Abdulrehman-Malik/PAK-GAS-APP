INSERT INTO settings(setting_group,setting_key,setting_value)
VALUES ('sales','pos_transaction_types','GAS_SALE,EMPTY_CYLINDER_SALE,CYLINDER_RETURN')
ON DUPLICATE KEY UPDATE setting_value='GAS_SALE,EMPTY_CYLINDER_SALE,CYLINDER_RETURN';

INSERT INTO settings(setting_group,setting_key,setting_value)
VALUES ('sales','pos_default_transaction_type','GAS_SALE')
ON DUPLICATE KEY UPDATE setting_value=IF(setting_value='',VALUES(setting_value),setting_value);
