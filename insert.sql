-- Dane z plików TXT - INSERT statements dla Price Tracker API
-- Wykonać po utworzeniu tabel

USE price_tracker;

-- Wyczyść istniejące dane (jeśli istnieją)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE prices;
TRUNCATE TABLE product_links;
TRUNCATE TABLE substitute_groups;
TRUNCATE TABLE products;
TRUNCATE TABLE shop_configs;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- USERS (na podstawie user_id z prices.txt i products.txt)
-- =====================================================

INSERT INTO users (user_id, instance_name, created_at, is_active, contributions_count) VALUES
('USR-9B25EFB9F425-77429277', 'PriceTracker-77429277', '2025-09-02 15:00:00', TRUE, 1),
('USR-ADMIN-00000000', 'Admin Instance', '2025-09-01 00:00:00', TRUE, 0),
('USR-SYSTEM-11111111', 'System User', '2025-09-01 00:00:00', TRUE, 0);

-- =====================================================
-- PRODUCTS (z products.txt)
-- =====================================================

INSERT INTO products (id, name, ean, created_by, created_at) VALUES
(1, 'Nasic Kids aerozol do nosa 10 ml', '5909990835645', 'USR-9B25EFB9F425-77429277', '2025-09-02 15:01:06'),
(2, 'Hascovir control MAX, 400 mg, tabletki, 30 szt.', '', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:16:53'),
(3, 'Xylometazolin WZF 0,1% krople do nosa 10 ml', '', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:32:54'),
(4, 'Tretussin Med 250 ml', '', 'USR-9B25EFB9F425-77429277', '2025-09-04 11:50:26'),
(5, 'ACTI-trin, syrop, 100 ml', '', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:28:30'),
(6, 'Rutinoscorbin, 150 tabletek', '', 'USR-9B25EFB9F425-77429277', '2025-09-04 22:16:23'),
(7, 'ACC Optima 600 mg, 10 tabletek', '', 'USR-9B25EFB9F425-77429277', '2025-09-05 08:44:56'),
(8, 'Essensey L-Tryptofan, 90 kaps', '', 'USR-9B25EFB9F425-77429277', '2025-09-05 09:24:22'),
(9, 'Rutinowitum C - 150 tabl', '', 'USR-9B25EFB9F425-77429277', '2025-09-05 10:38:16'),
(10, 'Giant Revolt 0 2024 Pyrite Brown M/L', '', 'USR-9B25EFB9F425-77429277', '2025-09-05 12:30:36'),
(12, 'Giant Revolt 1 M/L', '', 'USR-9B25EFB9F425-77429277', '2025-09-05 12:47:44');

-- =====================================================
-- SHOP_CONFIGS (z shop_config.txt)
-- =====================================================

INSERT INTO shop_configs (shop_id, name, price_selectors, delivery_free_from, delivery_cost, currency, search_config, updated_by, updated_at) VALUES
('aptekacurate', 'Apteka Curate', 
 '{"promo": ["#projector_price_value", ".projector_price_value"], "regular": [".price", ".product-price"]}', 
 299.00, 11.99, 'PLN',
 '{"search_url": "https://aptekacurate.pl/search.php?text={query}", "result_selectors": ["a.product__icon", "a[data-product-id]"], "title_selectors": [".product__name", "h3 > a.product__name"], "search_methods": ["name", "ean"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('aptekapomocna24', 'Apteka Pomocna 24', 
 '{"promo": ["#projector_prices__price"], "regular": [".product-price", ".projector_prices__price"]}', 
 199.00, 19.99, 'PLN',
 '{"search_url": "https://aptekapomocna24.pl/search.php?text={query}", "result_selectors": ["a.product__name"], "title_selectors": ["a.product__name"], "search_methods": ["name"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('rosa24', 'Rosa 24', 
 '{"promo": [".product-card__price-card-header__prices__price--purchase"], "regular": [".product-card__price"]}', 
 278.99, 12.00, 'PLN',
 '{"search_url": "https://www.rosa24.pl/znajdz/{query}", "result_selectors": ["a[href^=\"/produkt/\"]"], "title_selectors": ["a[href^=\"/produkt/\"] h4.product-preview-new__title span"], "search_methods": ["name", "ean"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('aptekaiderpl', 'Apteka Iderm', 
 '{"promo": ["span[data-subscription-max]"], "regular": [".product-price"]}', 
 250.00, 12.99, 'PLN',
 '{"search_url": "https://aptekaiderm.pl/pl/search.html?text={query}", "result_selectors": [".product-container a"], "title_selectors": [".product-name"], "search_methods": ["name", "ean"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('gemini', 'Gemini Apteka', 
 '{"promo": ["div.flex.items-baseline span"], "regular": [".price"]}', 
 249.00, 12.90, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('allegro-aptekarosa', 'Allegro - Apteka Rosa', 
 '{"promo": ["[data-testid=\"price-value\"]", ".price-sale"], "regular": [".price"]}', 
 NULL, 29.97, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('doz', 'DOZ Apteka', 
 '{"promo": [".price-sale"], "regular": [".price"]}', 
 NULL, 11.99, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('drmax', 'Dr Max', 
 '{"promo": ["[data-test-id=\"product-detail-box-price\"] .price-text span:first-child"], "regular": ["[data-test-id=\"product-detail-box-price\"]"]}', 
 199.00, 11.99, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('e-zikoapteka', 'E-Ziko Apteka', 
 '{"promo": ["[itemprop=\"price\"]"], "regular": [".price-container.price-value"]}', 
 199.00, 11.90, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('swiatleku', 'Świat Leku', 
 '{"promo": ["span.price-container.price-value"], "regular": ["[itemprop=\"price\"]"]}', 
 NULL, 13.00, 'PLN',
 '{"search_url": "https://www.swiatleku.pl/{query},n.html?name={query}", "result_selectors": [".price-value"], "title_selectors": ["a.product-name-link"], "search_methods": ["name"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('apteline', 'Apteline', 
 '{"promo": [".price-sale", ".price-promo"], "regular": [".price", ".product-price"]}', 
 1.00, NULL, 'PLN',
 '{"search_url": "https://apteline.pl/catalogsearch/result?q={query}", "result_selectors": [".product-item__name a"], "title_selectors": [".product-item__name a"], "search_methods": ["name"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('sport11', 'Sport 11', 
 '{"promo": [".price-sale", ".price-promo"], "regular": [".price", ".product-price"]}', 
 NULL, NULL, 'PLN',
 '{}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00'),

('sklep', 'Sklep Żuchliński', 
 '{"promo": [".red-price .value"], "regular": [".price .value"]}', 
 NULL, 16.00, 'PLN',
 '{"search_url": "https://sklep.zuchlinski.com.pl/produkty/2?search={query}", "result_selectors": ["a.productItemContainer"], "title_selectors": ["h2.productName"], "search_methods": ["name"]}',
 'USR-SYSTEM-11111111', '2025-09-02 15:00:00');

-- =====================================================
-- PRODUCT_LINKS (z product_links.txt - wybrane przykłady)
-- =====================================================

INSERT INTO product_links (product_id, shop_id, url, added_by, created_at) VALUES
-- Nasic Kids (ID: 1)
(1, 'rosa24', 'https://www.rosa24.pl/produkt/141493-nasic-kids-aerozol-do-nosa-10-ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-02 15:01:06'),
(1, 'aptekaiderpl', 'https://aptekaiderm.pl/pl/products/nasic-kids-aerozol-do-nosa-10-ml-2553.html', 'USR-9B25EFB9F425-77429277', '2025-09-02 15:05:45'),
(1, 'aptekacurate', 'https://aptekacurate.pl/product-pol-14925-Nasic-Kids-aerozol-do-nosa-10ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-02 15:08:53'),
(1, 'gemini', 'https://gemini.pl/nasic-kids-0-05-mg-5-mg-dawke-aerozol-do-nosa-dla-dzieci-od-2-do-6-lat-10-ml-0015329', 'USR-9B25EFB9F425-77429277', '2025-09-02 15:10:42'),
(1, 'doz', 'https://www.doz.pl/apteka/p57865-Nasic_Kids_005mg5mgdawke_aerozol_do_nosa_roztwor_10_ml', 'USR-9B25EFB9F425-77429277', '2025-09-04 13:19:22'),

-- Hascovir (ID: 2)
(2, 'doz', 'https://www.doz.pl/apteka/p141949-Hascovir_control_MAX_400_mg_tabletki_30_szt.', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:16:53'),
(2, 'rosa24', 'https://www.rosa24.pl/produkt/153239-hascovir-control-max-30-tabletek.html', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:17:50'),
(2, 'drmax', 'https://www.drmax.pl/hascovir-control-max-400-mg-30-tabletek-100015339', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:18:31'),
(2, 'e-zikoapteka', 'https://www.e-zikoapteka.pl/hascovir-control-max-400-mg-30-tabletek.html', 'USR-9B25EFB9F425-77429277', '2025-09-03 12:20:35'),
(2, 'swiatleku', 'https://www.swiatleku.pl/hascovir-control-max-30-tabletek.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:50:54'),

-- Xylometazolin (ID: 3) 
(3, 'rosa24', 'https://www.rosa24.pl/produkt/138477-xylometazolin-wzf-0-1-10-ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:24:18'),
(3, 'aptekacurate', 'https://aptekacurate.pl/product-pol-17342-Xylometazolin-WZF-0-1-krople-do-nosa-10ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 13:00:16'),
(3, 'doz', 'https://www.doz.pl/apteka/p4992-Xylometazolin_WZF_0.1_krople_do_nosa_10_ml', 'USR-9B25EFB9F425-77429277', '2025-09-04 13:19:39'),

-- Tretussin (ID: 4)
(4, 'rosa24', 'https://www.rosa24.pl/produkt/149202-domowa-apteczka-tretussin-med-syrop-250-ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:24:41'),
(4, 'aptekacurate', 'https://aptekacurate.pl/product-pol-29967-Tretussin-Med-syrop-250-ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 13:00:41'),

-- ACTI-trin (ID: 5)
(5, 'doz', 'https://www.doz.pl/apteka/p1898-ACTI-trin_syrop_100_ml', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:28:30'),
(5, 'swiatleku', 'https://www.swiatleku.pl/acti-trin-syrop-100-ml.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 12:49:41'),

-- Rutinoscorbin (ID: 6)
(6, 'aptekacurate', 'https://aptekacurate.pl/product-pol-18127-Rutinoscorbin-150-tabletek-powlekane.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 22:17:28'),
(6, 'swiatleku', 'https://www.swiatleku.pl/rutinoscorbin-150-tabletek.html', 'USR-9B25EFB9F425-77429277', '2025-09-04 22:17:55'),
(6, 'rosa24', 'https://www.rosa24.pl/produkt/139089-rutinoscorbin-150-tabletek.html', 'USR-9B25EFB9F425-77429277', '2025-09-05 08:04:10'),

-- ACC Optima (ID: 7)
(7, 'gemini', 'https://gemini.pl/acc-optima-600-mg-10-tabletek-musujacych-0000231', 'USR-9B25EFB9F425-77429277', '2025-09-05 08:44:56'),
(7, 'apteline', 'https://apteline.pl/acc-optima-600-mg-tabletki-musujace-10-szt', 'USR-9B25EFB9F425-77429277', '2025-09-05 09:04:20'),

-- L-Tryptofan (ID: 8)
(8, 'aptekaolmed', 'https://www.aptekaolmed.pl/produkt/essensey-l-tryptofan-90-kaps,168507.html', 'USR-9B25EFB9F425-77429277', '2025-09-05 09:24:22'),

-- Rutinowitum (ID: 9)
(9, 'aptekaolmed', 'https://www.aptekaolmed.pl/produkt/rutinowitum-c-150tabl-69511,69511.html', 'USR-9B25EFB9F425-77429277', '2025-09-05 10:38:16'),

-- Giant Revolt 0 (ID: 10)
(10, 'sport11', 'https://www.sport11.pl/pl/rower-szutrowy-giant-revolt-0-2024', 'USR-9B25EFB9F425-77429277', '2025-09-05 12:30:36'),
(10, 'sklep', 'https://sklep.zuchlinski.com.pl/rower-giant-revolt-0/3-174-9126', 'USR-9B25EFB9F425-77429277', '2025-09-05 12:33:44'),

-- Giant Revolt 1 (ID: 12)
(12, 'sklep', 'https://sklep.zuchlinski.com.pl/rower-giant-revolt-1/3-174-896', 'USR-9B25EFB9F425-77429277', '2025-09-05 12:47:44');

-- =====================================================
-- PRICES (z prices.txt - przykład jednej ceny)
-- =====================================================

INSERT INTO prices (product_id, shop_id, price, currency, price_type, url, user_id, source, created_at) VALUES
(6, 'aptekacurate', 17.46, 'PLN', 'unknown', 'https://aptekacurate.pl/product-pol-18127-Rutinoscorbin-150-tabletek-powlekane.html?query_id=1', 'USR-9B25EFB9F425-77429277', 'ajax_scraping', '2025-09-06 16:23:03');

-- =====================================================
-- SUBSTITUTE_GROUPS (z substitutes.txt)
-- =====================================================

INSERT INTO substitute_groups (group_id, name, product_ids, priority_map, settings, created_by, created_at) VALUES
('group_1_1757062275', 'Rutinoskorbin', 
 '[6, 9]', 
 '{"6": 1, "9": 1}',
 '{"max_price_increase_percent": 20.0, "min_quantity_ratio": 0.8, "max_quantity_ratio": 1.5, "allow_automatic_substitution": true}',
 'USR-9B25EFB9F425-77429277', '2025-09-05 10:51:15');

-- =====================================================
-- AKTUALIZUJ AUTO_INCREMENT (żeby kolejne ID były wyższe)
-- =====================================================

ALTER TABLE products AUTO_INCREMENT = 13;
ALTER TABLE product_links AUTO_INCREMENT = 100;
ALTER TABLE prices AUTO_INCREMENT = 10;
ALTER TABLE users AUTO_INCREMENT = 10;

-- =====================================================
-- SPRAWDŹ DANE
-- =====================================================

SELECT 'USERS' as table_name, COUNT(*) as count FROM users
UNION ALL
SELECT 'PRODUCTS', COUNT(*) FROM products  
UNION ALL
SELECT 'PRODUCT_LINKS', COUNT(*) FROM product_links
UNION ALL
SELECT 'PRICES', COUNT(*) FROM prices
UNION ALL
SELECT 'SHOP_CONFIGS', COUNT(*) FROM shop_configs
UNION ALL  
SELECT 'SUBSTITUTE_GROUPS', COUNT(*) FROM substitute_groups;

-- Test view
SELECT product_id, shop_id, price, currency FROM latest_prices LIMIT 5;