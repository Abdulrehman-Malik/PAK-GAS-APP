CREATE TABLE IF NOT EXISTS parties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    party_type ENUM('CUSTOMER','SUPPLIER','REFEREE') NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    address VARCHAR(500) NULL,
    allow_credit TINYINT(1) NOT NULL DEFAULT 0,
    credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_parties_code_ci (code),
    INDEX idx_parties_type_active (party_type, active),
    INDEX idx_parties_name (name),
    CONSTRAINT fk_parties_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_parties_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinder_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    capacity_kg DECIMAL(10,3) NOT NULL,
    cylinder_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinder_groups_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_groups_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (capacity_kg > 0),
    CHECK (cylinder_price >= 0),
    INDEX idx_cylinder_groups_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    group_id BIGINT UNSIGNED NOT NULL,
    gas_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL DEFAULT 'SHOP',
    customer_id BIGINT UNSIGNED NULL,
    condition_code ENUM('GOOD','DAMAGED') NOT NULL DEFAULT 'GOOD',
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinders_group FOREIGN KEY (group_id) REFERENCES cylinder_groups(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_customer FOREIGN KEY (customer_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinders_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (gas_kg >= 0),
    INDEX idx_cylinders_group (group_id),
    INDEX idx_cylinders_location (location),
    INDEX idx_cylinders_customer (customer_id),
    INDEX idx_cylinders_condition (condition_code),
    INDEX idx_cylinders_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    gas_rate DECIMAL(10,2) NOT NULL,
    cylinder_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_rates_group FOREIGN KEY (group_id) REFERENCES cylinder_groups(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (gas_rate >= 0),
    CHECK (cylinder_price >= 0),
    UNIQUE KEY uq_rates_group_date (group_id, effective_date),
    INDEX idx_rates_effective (group_id, effective_date, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cylinder_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cylinder_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(50) NOT NULL,
    before_gas_kg DECIMAL(10,3) NOT NULL,
    after_gas_kg DECIMAL(10,3) NOT NULL,
    from_location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL,
    to_location ENUM('SHOP','CUSTOMER','SOLD') NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    rate DECIMAL(10,2) NULL,
    source_document_type VARCHAR(50) NULL,
    source_document_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cylinder_movements_cylinder FOREIGN KEY (cylinder_id) REFERENCES cylinders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_movements_customer FOREIGN KEY (customer_id) REFERENCES parties(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cylinder_movements_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_cylinder_movements_cylinder (cylinder_id, created_at),
    INDEX idx_cylinder_movements_source (source_document_type, source_document_id),
    INDEX idx_cylinder_movements_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_cylinder_status AS
SELECT
    c.id,
    c.code,
    c.group_id,
    cg.code AS group_code,
    cg.name AS group_name,
    cg.capacity_kg,
    c.gas_kg,
    c.location,
    c.customer_id,
    c.condition_code,
    c.active,
    CASE
        WHEN c.location = 'CUSTOMER' THEN 'ISSUED'
        WHEN c.location = 'SOLD' THEN 'SOLD'
        WHEN c.gas_kg = cg.capacity_kg THEN 'FILLED'
        WHEN c.gas_kg > 0 THEN 'PARTIAL'
        ELSE 'EMPTY'
    END AS status
FROM cylinders c
INNER JOIN cylinder_groups cg ON cg.id = c.group_id;

CREATE OR REPLACE VIEW v_shop_stock_summary AS
SELECT
    c.group_id,
    cg.code AS group_code,
    cg.name AS group_name,
    cg.capacity_kg,
    COUNT(*) AS cylinder_count,
    COALESCE(SUM(c.gas_kg), 0) AS gas_kg
FROM cylinders c
INNER JOIN cylinder_groups cg ON cg.id = c.group_id
WHERE c.location = 'SHOP' AND c.condition_code = 'GOOD' AND c.active = 1
GROUP BY c.group_id, cg.code, cg.name, cg.capacity_kg;
