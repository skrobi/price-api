-- Baza danych Price Tracker - wersja uproszczona
-- Utworzenie bazy danych
CREATE DATABASE price_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE price_tracker;

-- Tabela użytkowników
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) UNIQUE NOT NULL,
    instance_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    contributions_count INT DEFAULT 0,
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
);

-- Tabela produktów
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    ean VARCHAR(20),
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_ean (ean),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- Tabela linków produktów
CREATE TABLE product_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT,
    shop_id VARCHAR(50),
    url TEXT NOT NULL,
    added_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_shop (product_id, shop_id),
    INDEX idx_shop (shop_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(user_id)
);

-- Tabela cen
CREATE TABLE prices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT,
    shop_id VARCHAR(50),
    price DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'PLN',
    price_type VARCHAR(20),
    url TEXT,
    user_id VARCHAR(50),
    source VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_shop (product_id, shop_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Tabela konfiguracji sklepów
CREATE TABLE shop_configs (
    shop_id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(100),
    price_selectors JSON,
    delivery_free_from DECIMAL(10,2) NULL,
    delivery_cost DECIMAL(10,2) NULL,
    currency VARCHAR(3) DEFAULT 'PLN',
    search_config JSON,
    updated_by VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id)
);

-- Tabela grup zamienników
CREATE TABLE substitute_groups (
    group_id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255),
    product_ids JSON,
    priority_map JSON,
    settings JSON,
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- Widok najnowszych cen
CREATE VIEW latest_prices AS
SELECT 
    p.product_id, 
    p.shop_id, 
    p.price, 
    p.currency, 
    p.created_at,
    ROW_NUMBER() OVER (PARTITION BY p.product_id, p.shop_id ORDER BY p.created_at DESC) as rn
FROM prices p
HAVING rn = 1;

