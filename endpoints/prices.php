<?php
/**
 * API Endpoint: /api/prices.php
 * Obsługa cen - dodawanie, pobieranie najnowszych cen
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'latest';
$user_id = ApiHelper::getUserId();

ApiHelper::checkRateLimit($user_id);
ApiHelper::logRequest('/api/prices.php', $user_id, $method, $_POST);
ApiHelper::ensureUserExists($user_id);

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    switch ($method) {
        case 'GET':
            handleGet($pdo, $action, $user_id);
            break;
            
        case 'POST':
            handlePost($pdo, $action, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("API Error in prices.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'latest':
            getLatestPrices($pdo, $user_id);
            break;
            
        case 'for_product':
            getPricesForProduct($pdo, $user_id);
            break;
            
        case 'history':
            getPriceHistory($pdo, $user_id);
            break;
            
        case 'stats':
            getPriceStats($pdo, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

function handlePost($pdo, $action, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiHelper::jsonError('Invalid JSON input');
    }
    
    switch ($action) {
        case 'add':
            addPrice($pdo, $input, $user_id);
            break;
            
        case 'bulk_add':
            bulkAddPrices($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Pobiera najnowsze ceny dla wszystkich produktów
 */
function getLatestPrices($pdo, $user_id) {
    $product_ids = $_GET['product_ids'] ?? '';
    $shop_ids = $_GET['shop_ids'] ?? '';
    $limit = min(intval($_GET['limit'] ?? 1000), 5000);
    
    try {
        $where_conditions = [];
        $params = [];
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', explode(',', $product_ids));
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $where_conditions[] = "lp.product_id IN ($placeholders)";
            $params = array_merge($params, $ids);
        }
        
        if (!empty($shop_ids)) {
            $shops = explode(',', $shop_ids);
            $placeholders = str_repeat('?,', count($shops) - 1) . '?';
            $where_conditions[] = "lp.shop_id IN ($placeholders)";
            $params = array_merge($params, $shops);
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $sql = "SELECT lp.product_id, lp.shop_id, lp.price, lp.currency, lp.created_at,
                       p.name as product_name, p.ean,
                       sc.name as shop_name
                FROM latest_prices lp
                LEFT JOIN products p ON lp.product_id = p.id
                LEFT JOIN shop_configs sc ON lp.shop_id = sc.shop_id
                $where_clause
                ORDER BY lp.product_id, lp.shop_id
                LIMIT ?";
        
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $prices = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'prices' => $prices,
            'count' => count($prices),
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera ceny dla konkretnego produktu
 */
function getPricesForProduct($pdo, $user_id) {
    $product_id = intval($_GET['product_id'] ?? 0);
    $days = min(intval($_GET['days'] ?? 7), 90);
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    try {
        $sql = "SELECT pr.shop_id, pr.price, pr.currency, pr.price_type, pr.created_at,
                       sc.name as shop_name,
                       sc.delivery_cost, sc.delivery_free_from
                FROM prices pr
                LEFT JOIN shop_configs sc ON pr.shop_id = sc.shop_id
                WHERE pr.product_id = ? AND pr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY pr.shop_id, pr.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$product_id, $days]);
        $prices = $stmt->fetchAll();
        
        // Grupuj po sklepach
        $by_shop = [];
        foreach ($prices as $price) {
            $shop_id = $price['shop_id'];
            if (!isset($by_shop[$shop_id])) {
                $by_shop[$shop_id] = [
                    'shop_id' => $shop_id,
                    'shop_name' => $price['shop_name'],
                    'delivery_cost' => $price['delivery_cost'],
                    'delivery_free_from' => $price['delivery_free_from'],
                    'prices' => []
                ];
            }
            $by_shop[$shop_id]['prices'][] = $price;
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'product_id' => $product_id,
            'by_shop' => array_values($by_shop),
            'all_prices' => $prices,
            'days' => $days
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Historia cen (dla wykresów)
 */
function getPriceHistory($pdo, $user_id) {
    $product_id = intval($_GET['product_id'] ?? 0);
    $shop_id = $_GET['shop_id'] ?? '';
    $days = min(intval($_GET['days'] ?? 30), 365);
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    try {
        $where_clause = 'WHERE product_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$product_id, $days];
        
        if (!empty($shop_id)) {
            $where_clause .= ' AND shop_id = ?';
            $params[] = $shop_id;
        }
        
        $sql = "SELECT shop_id, price, currency, created_at, price_type
                FROM prices
                $where_clause
                ORDER BY created_at ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'product_id' => $product_id,
            'shop_id' => $shop_id,
            'history' => $history,
            'days' => $days,
            'count' => count($history)
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Statystyki cen użytkownika
 */
function getPriceStats($pdo, $user_id) {
    try {
        // Liczba cen dodanych przez użytkownika
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_prices FROM prices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $total_prices = $stmt->fetch()['total_prices'];
        
        // Ceny z ostatnich 7 dni
        $stmt = $pdo->prepare("SELECT COUNT(*) as recent_prices FROM prices WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$user_id]);
        $recent_prices = $stmt->fetch()['recent_prices'];
        
        // Sklepy w których dodawał ceny
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT shop_id) as shops_count FROM prices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $shops_count = $stmt->fetch()['shops_count'];
        
        // Produkty dla których dodawał ceny
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT product_id) as products_count FROM prices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $products_count = $stmt->fetch()['products_count'];
        
        ApiHelper::jsonResponse([
            'success' => true,
            'user_id' => $user_id,
            'stats' => [
                'total_prices' => intval($total_prices),
                'recent_prices' => intval($recent_prices),
                'shops_count' => intval($shops_count),
                'products_count' => intval($products_count)
            ]
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Dodaje nową cenę
 */
function addPrice($pdo, $input, $user_id) {
    $product_id = intval($input['product_id'] ?? 0);
    $shop_id = trim($input['shop_id'] ?? '');
    $price = floatval($input['price'] ?? 0);
    $currency = trim($input['currency'] ?? 'PLN');
    $price_type = trim($input['price_type'] ?? 'manual');
    $url = trim($input['url'] ?? '');
    $source = trim($input['source'] ?? 'api');
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    if (empty($shop_id)) {
        ApiHelper::jsonError('Shop ID is required');
    }
    
    if ($price <= 0 || $price > 999999.99) {
        ApiHelper::jsonError('Valid price is required (0.01 - 999999.99)');
    }
    
    if (!in_array($currency, ['PLN', 'EUR', 'USD'])) {
        ApiHelper::jsonError('Currency must be PLN, EUR or USD');
    }
    
    try {
        // Sprawdź czy produkt istnieje
        $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        
        if ($stmt->rowCount() === 0) {
            ApiHelper::jsonError('Product not found', 404);
        }
        
        // Dodaj cenę
        $stmt = $pdo->prepare("INSERT INTO prices (product_id, shop_id, price, currency, price_type, url, user_id, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, $shop_id, $price, $currency, $price_type, $url, $user_id, $source]);
        
        $price_id = $pdo->lastInsertId();
        
        // Zwiększ licznik contributions
        $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'price_id' => intval($price_id),
            'product_id' => $product_id,
            'shop_id' => $shop_id,
            'price' => $price,
            'currency' => $currency,
            'message' => 'Price added successfully'
        ], 201);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to add price: ' . $e->getMessage());
    }
}

/**
 * Dodaje wiele cen naraz
 */
function bulkAddPrices($pdo, $input, $user_id) {
    $prices = $input['prices'] ?? [];
    
    if (empty($prices) || !is_array($prices)) {
        ApiHelper::jsonError('Prices array is required');
    }
    
    if (count($prices) > 500) {
        ApiHelper::jsonError('Maximum 500 prices per batch');
    }
    
    $results = [];
    $added_count = 0;
    $skipped_count = 0;
    
    try {
        $pdo->beginTransaction();
        
        // Pre-load existing products for validation
        $all_product_ids = array_unique(array_map(function($p) { return intval($p['product_id'] ?? 0); }, $prices));
        $existing_products = [];
        
        if (!empty($all_product_ids)) {
            $placeholders = str_repeat('?,', count($all_product_ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id IN ($placeholders)");
            $stmt->execute($all_product_ids);
            $existing_products = array_column($stmt->fetchAll(), 'id');
        }
        
        foreach ($prices as $index => $price) {
            $product_id = intval($price['product_id'] ?? 0);
            $shop_id = trim($price['shop_id'] ?? '');
            $price_value = floatval($price['price'] ?? 0);
            $currency = trim($price['currency'] ?? 'PLN');
            $price_type = trim($price['price_type'] ?? 'scraped');
            $url = trim($price['url'] ?? '');
            $source = trim($price['source'] ?? 'bulk_api');
            $local_id = $price['local_id'] ?? $index;
            
            // Walidacja
            if ($product_id <= 0 || !in_array($product_id, $existing_products)) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Product not found'
                ];
                $skipped_count++;
                continue;
            }
            
            if (empty($shop_id) || $price_value <= 0 || $price_value > 999999.99) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Invalid data'
                ];
                $skipped_count++;
                continue;
            }
            
            if (!in_array($currency, ['PLN', 'EUR', 'USD'])) {
                $currency = 'PLN'; // Fallback
            }
            
            // Dodaj cenę
            $stmt = $pdo->prepare("INSERT INTO prices (product_id, shop_id, price, currency, price_type, url, user_id, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $shop_id, $price_value, $currency, $price_type, $url, $user_id, $source]);
            
            $results[] = [
                'local_id' => $local_id,
                'success' => true,
                'price_id' => intval($pdo->lastInsertId()),
                'product_id' => $product_id,
                'shop_id' => $shop_id,
                'price' => $price_value
            ];
            $added_count++;
        }
        
        // Aktualizuj licznik contributions
        if ($added_count > 0) {
            $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + ? WHERE user_id = ?");
            $stmt->execute([$added_count, $user_id]);
        }
        
        $pdo->commit();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total_processed' => count($prices),
                'added' => $added_count,
                'skipped' => $skipped_count
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        ApiHelper::jsonError('Bulk add failed: ' . $e->getMessage());
    }
}

?>