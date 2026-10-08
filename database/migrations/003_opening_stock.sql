CREATE TABLE IF NOT EXISTS stock_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_date DATE NOT NULL,
    source ENUM('MANUAL','IMPORT') NOT NULL,
    status ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    voided_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,
    CONSTRAINT fk_stock_batches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_batches_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_stock_batches_date (batch_date),
    INDEX idx_stock_batches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_type VARCHAR(50) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_ok INT UNSIGNED NOT NULL DEFAULT 0,
    rows_error INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('PREVIEW','COMMITTED','FAILED') NOT NULL DEFAULT 'PREVIEW',
    error_report_path VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    INDEX idx_import_batches_type_date (import_type, created_at),
    CONSTRAINT fk_import_batches_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cylinder_movements ADD COLUMN stock_batch_id BIGINT UNSIGNED NULL AFTER source_document_id;
ALTER TABLE cylinder_movements ADD INDEX idx_cylinder_movements_batch (stock_batch_id);
ALTER TABLE cylinder_movements ADD CONSTRAINT fk_cylinder_movements_batch FOREIGN KEY (stock_batch_id) REFERENCES stock_batches(id) ON DELETE RESTRICT;
