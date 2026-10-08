ALTER TABLE doc_sequences
    ADD COLUMN width INT UNSIGNED NOT NULL DEFAULT 6 AFTER prefix;

UPDATE doc_sequences SET width=6 WHERE width IS NULL OR width=0;

INSERT INTO doc_sequences(doc_type,prefix,width,last_value)
VALUES
('SALE','SAL-',6,0),
('RECEIPT','RCT-',6,0),
('PURCHASE','PUR-',6,0),
('PAYMENT','PAY-',6,0),
('EXPENSE','EXP-',6,0)
ON DUPLICATE KEY UPDATE prefix=VALUES(prefix),width=VALUES(width);
