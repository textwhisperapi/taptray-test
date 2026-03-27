CREATE TABLE IF NOT EXISTS rp_tables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    table_code VARCHAR(40) NOT NULL,
    label VARCHAR(80) NOT NULL,
    zone VARCHAR(80) NULL,
    capacity_min INT UNSIGNED NOT NULL DEFAULT 1,
    capacity_max INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_tables_merchant_code (merchant_id, table_code),
    KEY idx_rp_tables_merchant_active_sort (merchant_id, is_active, sort_order),
    KEY idx_rp_tables_location (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rp_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_ref VARCHAR(40) NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    customer_email VARCHAR(160) NULL,
    party_size INT UNSIGNED NOT NULL,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    status ENUM('new','confirmed','arrived','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'new',
    source ENUM('web','walk_in','phone','staff') NOT NULL DEFAULT 'web',
    notes TEXT NULL,
    internal_notes TEXT NULL,
    table_assignment_mode ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_reservations_ref (reservation_ref),
    KEY idx_rp_reservations_merchant_date_time (merchant_id, reservation_date, start_time),
    KEY idx_rp_reservations_merchant_status_date (merchant_id, status, reservation_date),
    KEY idx_rp_reservations_location_date (location_id, reservation_date),
    KEY idx_rp_reservations_phone (customer_phone),
    KEY idx_rp_reservations_email (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rp_reservation_tables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    table_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_reservation_table (reservation_id, table_id),
    KEY idx_rp_reservation_tables_table (table_id),
    CONSTRAINT fk_rp_reservation_tables_reservation
        FOREIGN KEY (reservation_id) REFERENCES rp_reservations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_rp_reservation_tables_table
        FOREIGN KEY (table_id) REFERENCES rp_tables (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rp_availability_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    open_time TIME NOT NULL,
    close_time TIME NOT NULL,
    slot_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    max_party_size INT UNSIGNED NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rp_availability_merchant_day (merchant_id, day_of_week),
    KEY idx_rp_availability_location_day (location_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rp_blackouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rp_blackouts_merchant_range (merchant_id, start_at, end_at),
    KEY idx_rp_blackouts_location_range (location_id, start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
