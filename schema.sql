-- ============================================================================
-- StockPulse — Smart Warehouse & Stock Movement Tracker
-- MySQL schema (InnoDB, 3NF) with foreign key constraints + demo data
-- ============================================================================
-- Import:  mysql -u root -p < schema.sql
-- Requires: MySQL 5.7+ / MariaDB 10.3+ (CHECK constraints enforced on 8.0.16+)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS stockpulse
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE stockpulse;

-- ----------------------------------------------------------------------------
-- Drop existing tables (reverse dependency order) so the script is re-runnable
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS stock_logs;
DROP TABLE IF EXISTS warehouse_stock;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

-- ============================================================================
-- 1. users — application accounts (passwords are bcrypt hashes via password_hash)
--    Demo credentials:
--      admin@stockpulse.local  / admin1234   (role: admin)
--      sara@stockpulse.local   / manager1234 (role: manager)
--      mike@stockpulse.local   / staff1234   (role: staff)
-- ============================================================================
CREATE TABLE users (
    user_id      INT AUTO_INCREMENT PRIMARY KEY,
    full_name    VARCHAR(120)  NOT NULL,
    email        VARCHAR(191)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt hash generated with password_hash()',
    role         ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB;

-- ============================================================================
-- 2. categories — product catalog grouping
-- ============================================================================
CREATE TABLE categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_categories_name (category_name)
) ENGINE = InnoDB;

-- ============================================================================
-- 3. products — catalog items (3NF: category_id is a FK to categories)
-- ============================================================================
CREATE TABLE products (
    product_id   INT AUTO_INCREMENT PRIMARY KEY,
    sku          VARCHAR(50)   NOT NULL,
    product_name VARCHAR(200)  NOT NULL,
    category_id  INT           NOT NULL,
    unit_price   DECIMAL(10,2) NOT NULL,
    reorder_level INT          NOT NULL DEFAULT 10 COMMENT 'Alert threshold for total quantity',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE = InnoDB;

-- ============================================================================
-- 4. warehouse_stock — on-hand quantity per product per zone
--    One row per (product, zone) pair enforced by the unique key, which the
--    transfer upsert (ON DUPLICATE KEY UPDATE) relies on.
-- ============================================================================
CREATE TABLE warehouse_stock (
    stock_id   INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT          NOT NULL,
    zone_name  VARCHAR(60)  NOT NULL,
    quantity   INT          NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_product_zone UNIQUE KEY (product_id, zone_name),
    CONSTRAINT fk_stock_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_stock_quantity CHECK (quantity >= 0)
) ENGINE = InnoDB;

-- ============================================================================
-- 5. stock_logs — audit trail of every inward / outward / transfer movement
-- ============================================================================
CREATE TABLE stock_logs (
    log_id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT          NOT NULL,
    user_id       INT          NOT NULL,
    movement_type ENUM('inward', 'outward', 'transfer') NOT NULL,
    quantity      INT          NOT NULL,
    notes         VARCHAR(255) NULL,
    logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_logs_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_logs_quantity CHECK (quantity > 0),
    KEY idx_logs_product (product_id),
    KEY idx_logs_logged_at (logged_at)
) ENGINE = InnoDB;

-- ============================================================================
-- DEMO DATA
-- ============================================================================

-- Users (hashes generated with password_hash($password, PASSWORD_DEFAULT))
INSERT INTO users (full_name, email, password_hash, role) VALUES
('Admin User', 'admin@stockpulse.local', '$2b$12$ZIPlmVLCggup2hPCTwuaSueakg8aeTst/enPJBzuK1pRcxrpeO0H.', 'admin'),
('Sara Khan',  'sara@stockpulse.local',  '$2b$12$JeeciRWQ8/RFMHlhPDTMleLLMzn4VPKh0hsiddeam5H92z0rQ2Qgi', 'manager'),
('Mike Ross',  'mike@stockpulse.local',  '$2b$12$qzm2Zn6bOHlBC6jdi5PckeRzsID3juh978S9q/Y0ZWF0cSzLToxDG', 'staff');

-- Categories
INSERT INTO categories (category_name) VALUES
('Electronics'),
('Furniture'),
('Grocery'),
('Apparel');

-- Products
INSERT INTO products (sku, product_name, category_id, unit_price, reorder_level) VALUES
('SKU-ELC-001', 'Wireless Mouse 2.4GHz',        1,  24.99, 20),
('SKU-ELC-002', 'Mechanical Keyboard RGB',      1,  89.99, 15),
('SKU-ELC-003', 'USB-C Hub 7-in-1',             1,  34.50, 25),
('SKU-FRN-001', 'Ergonomic Office Chair',       2, 149.99,  5),
('SKU-FRN-002', 'Height-Adjustable Standing Desk', 2, 399.00, 3),
('SKU-GRC-001', 'Arabica Coffee Beans 1kg',     3,  12.75, 40),
('SKU-GRC-002', 'Premium Green Tea Box (50pk)', 3,   8.99, 50),
('SKU-APP-001', 'Cotton T-Shirt (Size M)',      4,  19.95, 30);

-- Warehouse stock — zones: Zone A (Receiving), Zone B (Shelving),
-- Zone C (Cold Storage), Zone D (Overflow)
-- Note: SKU-ELC-003 (17 units <= reorder 25) and SKU-GRC-002 (32 <= 50)
-- are intentionally low to demo the low-stock alerts.
INSERT INTO warehouse_stock (product_id, zone_name, quantity) VALUES
(1, 'Zone A',  45),  (1, 'Zone B', 120), (1, 'Zone D',  30),   -- Wireless Mouse: 195
(2, 'Zone A',  12),  (2, 'Zone B',  60),                          -- Keyboard: 72
(3, 'Zone A',   5),  (3, 'Zone B',   8), (3, 'Zone C',   4),     -- USB-C Hub: 17 (LOW)
(4, 'Zone A',   3),  (4, 'Zone B',  14),                          -- Office Chair: 17
(5, 'Zone B',   6),                                               -- Standing Desk: 6
(6, 'Zone A',  80),  (6, 'Zone C',  45),                          -- Coffee: 125
(7, 'Zone A',  20),  (7, 'Zone B',  12),                          -- Green Tea: 32 (LOW)
(8, 'Zone A',  15),  (8, 'Zone B',  35), (8, 'Zone D',  10);     -- T-Shirt: 60

-- Movement history
INSERT INTO stock_logs (product_id, user_id, movement_type, quantity, notes, logged_at) VALUES
(6, 1, 'inward',  200, 'Initial stock receiving from GreenField Foods', NOW() - INTERVAL 5 DAY),
(1, 2, 'inward',  150, 'Supplier restock - TechSupply Co.',              NOW() - INTERVAL 4 DAY),
(7, 3, 'outward',  18, 'Order #INV-1042 fulfilled',                      NOW() - INTERVAL 3 DAY),
(3, 1, 'transfer',  4, 'Transferred 4 unit(s) from Zone A to Zone B',    NOW() - INTERVAL 2 DAY),
(2, 2, 'outward',   8, 'Order #INV-1057 fulfilled',                      NOW() - INTERVAL 2 DAY),
(8, 3, 'inward',   60, 'Seasonal inventory intake',                      NOW() - INTERVAL 1 DAY),
(6, 1, 'transfer', 25, 'Transferred 25 unit(s) from Zone A to Zone C',   NOW() - INTERVAL 6 HOUR),
(1, 2, 'outward',  15, 'Order #INV-1089 fulfilled',                      NOW() - INTERVAL 2 HOUR);

-- Verify import
SELECT 'StockPulse schema imported successfully.' AS status;
SELECT c.category_name, COUNT(p.product_id) AS products
FROM categories c LEFT JOIN products p ON p.category_id = c.category_id
GROUP BY c.category_name;
