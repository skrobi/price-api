<?php
/**
 * API Endpoint: /api/shop_configs.php
 * Obsługa konfiguracji sklepów - selektory, dostawy, wyszukiwanie
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$user_id = ApiHelper::getUserId();

ApiHelper::checkRateLimit($user_id);
ApiHelper::logRequest('/api/shop_configs.php', $user_id, $method, $_POST);
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
    error_log("API Error in shop_configs.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'list':
            getShopConfigs($pdo, $user_id);
            break;
            
        case 'get':
            getShopConfig($pdo, $user_id);
            break;
            
        case 'selectors':
            getShopSelectors($pdo, $user_id);
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
        case 'update':
            updateShopConfig($pdo, $input, $user_id);
            break;
            
        case 'bulk_update':
            bulkUpdateShopConfigs($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Pobiera wszystkie konfiguracje sklepów
 */
function getShopConfigs($pdo, $user_id) {
    $modified_since = $_GET['modified_since'] ?? '';
    
    try {
        $where_clause = '';
        $params = [];
        
        if (!empty($modified_since)) {
            $where_clause = 'WHERE updated_at > ?';
            $params[] = $modified_since;
        }
        
        $sql = "SELECT shop_id, name, price_selectors, delivery_free_from, delivery_cost, 
                       currency, search_config, updated_by, updated_at
                FROM shop_configs 
                $where_clause
                ORDER BY shop_id ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $configs = $stmt->fetchAll();
        
        // Dekoduj JSON fields
        foreach ($configs as &$config) {
            $config['price_selectors'] = json_decode($config['price_selectors'], true);
            $config['search_config'] = json_decode($config['search_config'], true);
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'shop_configs' => $configs,
            'count' => count($configs),
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera konfigurację konkretnego sklepu
 */
function getShopConfig($pdo, $user_id) {
    $shop_id = $_GET['shop_id'] ?? '';
    
    if (empty($shop_id)) {
        ApiHelper::jsonError('Shop ID is required');
    }
    
    try {
        $sql = "SELECT shop_id, name, price_selectors, delivery_free_from, delivery_cost, 
                       currency, search_config, updated_by, updated_at
                FROM shop_configs 
                WHERE shop_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shop_id]);
        $config = $stmt->fetch();
        
        if (!$config) {
            ApiHelper::jsonError('Shop config not found', 404);
        }
        
        // Dekoduj JSON fields
        $config['price_selectors'] = json_decode($config['price_selectors'], true);
        $config['search_config'] = json_decode($config['search_config'], true);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'shop_config' => $config
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera tylko selektory dla konkretnego sklepu
 */
function getShopSelectors($pdo, $user_id) {
    $shop_id = $_GET['shop_id'] ?? '';
    
    if (empty($shop_id)) {
        ApiHelper::jsonError('Shop ID is required');
    }
    
    try {
        $sql = "SELECT shop_id, price_selectors, updated_at FROM shop_configs WHERE shop_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$shop_id]);
        $config = $stmt->fetch();
        
        if (!$config) {
            // Zwróć domyślne selektory
            ApiHelper::jsonResponse([
                'success' => true,
                'shop_id' => $shop_id,
                'selectors' => getDefaultSelectors($shop_id),
                'is_default' => true
            ]);
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'shop_id' => $shop_id,
            'selectors' => json_decode($config['price_selectors'], true),
            'updated_at' => $config['updated_at'],
            'is_default' => false
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Aktualizuje konfigurację sklepu
 */
function updateShopConfig($pdo, $input, $user_id) {
    $shop_id = trim($input['shop_id'] ?? '');
    $name = trim($input['name'] ?? '');
    $price_selectors = $input['price_selectors'] ?? [];
    $delivery_free_from = $input['delivery_free_from'] ?? null;
    $delivery_cost = $input['delivery_cost'] ?? null;
    $currency = trim($input['currency'] ?? 'PLN');
    $search_config = $input['search_config'] ?? [];
    
    if (empty($shop_id)) {
        ApiHelper::jsonError('Shop ID is required');
    }
    
    if (empty($name)) {
        $name = $shop_id;
    }
    
    // Walidacja selektorów
    if (!empty($price_selectors) && !is_array($price_selectors)) {
        ApiHelper::jsonError('Price selectors must be an array');
    }
    
    // Walidacja search_config
    if (!empty($search_config) && !is_array($search_config)) {
        ApiHelper::jsonError('Search config must be an array');
    }
    
    // Walidacja currency
    if (!in_array($currency, ['PLN', 'EUR', 'USD'])) {
        ApiHelper::jsonError('Currency must be PLN, EUR or USD');
    }
    
    try {
        // Sprawdź czy konfiguracja istnieje
        $stmt = $pdo->prepare("SELECT shop_id FROM shop_configs WHERE shop_id = ?");
        $stmt->execute([$shop_id]);
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            // Update
            $sql = "UPDATE shop_configs SET 
                    name = ?, 
                    price_selectors = ?, 
                    delivery_free_from = ?, 
                    delivery_cost = ?, 
                    currency = ?, 
                    search_config = ?, 
                    updated_by = ?, 
                    updated_at = NOW() 
                    WHERE shop_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name,
                json_encode($price_selectors, JSON_UNESCAPED_UNICODE),
                $delivery_free_from,
                $delivery_cost,
                $currency,
                json_encode($search_config, JSON_UNESCAPED_UNICODE),
                $user_id,
                $shop_id
            ]);
            
        } else {
            // Insert
            $sql = "INSERT INTO shop_configs (shop_id, name, price_selectors, delivery_free_from, delivery_cost, currency, search_config, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $shop_id,
                $name,
                json_encode($price_selectors, JSON_UNESCAPED_UNICODE),
                $delivery_free_from,
                $delivery_cost,
                $currency,
                json_encode($search_config, JSON_UNESCAPED_UNICODE),
                $user_id
            ]);
        }
        
        // Zwiększ licznik contributions
        $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'shop_id' => $shop_id,
            'action' => $exists ? 'updated' : 'created',
            'message' => 'Shop config saved successfully'
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to save shop config: ' . $e->getMessage());
    }
}

/**
 * Aktualizuje wiele konfiguracji sklepów naraz
 */
function bulkUpdateShopConfigs($pdo, $input, $user_id) {
    $configs = $input['configs'] ?? [];
    
    if (empty($configs) || !is_array($configs)) {
        ApiHelper::jsonError('Configs array is required');
    }
    
    if (count($configs) > 50) {
        ApiHelper::jsonError('Maximum 50 configs per batch');
    }
    
    $results = [];
    $updated_count = 0;
    $created_count = 0;
    $skipped_count = 0;
    
    try {
        $pdo->beginTransaction();
        
        foreach ($configs as $index => $config) {
            $shop_id = trim($config['shop_id'] ?? '');
            $name = trim($config['name'] ?? $shop_id);
            $price_selectors = $config['price_selectors'] ?? [];
            $delivery_free_from = $config['delivery_free_from'] ?? null;
            $delivery_cost = $config['delivery_cost'] ?? null;
            $currency = trim($config['currency'] ?? 'PLN');
            $search_config = $config['search_config'] ?? [];
            
            if (empty($shop_id)) {
                $results[] = [
                    'index' => $index,
                    'shop_id' => $shop_id,
                    'success' => false,
                    'error' => 'Shop ID is required'
                ];
                $skipped_count++;
                continue;
            }
            
            if (!in_array($currency, ['PLN', 'EUR', 'USD'])) {
                $currency = 'PLN';
            }
            
            // Sprawdź czy istnieje
            $stmt = $pdo->prepare("SELECT shop_id FROM shop_configs WHERE shop_id = ?");
            $stmt->execute([$shop_id]);
            $exists = $stmt->rowCount() > 0;
            
            if ($exists) {
                // Update
                $sql = "UPDATE shop_configs SET 
                        name = ?, 
                        price_selectors = ?, 
                        delivery_free_from = ?, 
                        delivery_cost = ?, 
                        currency = ?, 
                        search_config = ?, 
                        updated_by = ?, 
                        updated_at = NOW() 
                        WHERE shop_id = ?";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $name,
                    json_encode($price_selectors, JSON_UNESCAPED_UNICODE),
                    $delivery_free_from,
                    $delivery_cost,
                    $currency,
                    json_encode($search_config, JSON_UNESCAPED_UNICODE),
                    $user_id,
                    $shop_id
                ]);
                
                $updated_count++;
                
            } else {
                // Insert
                $sql = "INSERT INTO shop_configs (shop_id, name, price_selectors, delivery_free_from, delivery_cost, currency, search_config, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $shop_id,
                    $name,
                    json_encode($price_selectors, JSON_UNESCAPED_UNICODE),
                    $delivery_free_from,
                    $delivery_cost,
                    $currency,
                    json_encode($search_config, JSON_UNESCAPED_UNICODE),
                    $user_id
                ]);
                
                $created_count++;
            }
            
            $results[] = [
                'index' => $index,
                'shop_id' => $shop_id,
                'success' => true,
                'action' => $exists ? 'updated' : 'created'
            ];
        }
        
        // Aktualizuj licznik contributions
        $total_contributions = $updated_count + $created_count;
        if ($total_contributions > 0) {
            $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + ? WHERE user_id = ?");
            $stmt->execute([$total_contributions, $user_id]);
        }
        
        $pdo->commit();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total_processed' => count($configs),
                'created' => $created_count,
                'updated' => $updated_count,
                'skipped' => $skipped_count
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        ApiHelper::jsonError('Bulk update failed: ' . $e->getMessage());
    }
}

/**
 * Zwraca domyślne selektory dla sklepu
 */
function getDefaultSelectors($shop_id) {
    $defaults = [
        'allegro' => [
            'promo' => ['.offer-price__number', '.price-sale'],
            'regular' => ['.price', '.allegro-price']
        ],
        'amazon' => [
            'promo' => ['.a-price .a-offscreen'],
            'regular' => ['.a-price-value']
        ],
        'doz' => [
            'promo' => ['.price-sale'],
            'regular' => ['.price']
        ]
    ];
    
    $shop_lower = strtolower($shop_id);
    
    foreach ($defaults as $key => $selectors) {
        if (strpos($shop_lower, $key) !== false) {
            return $selectors;
        }
    }
    
    // Domyślne selektory dla nieznanych sklepów
    return [
        'promo' => ['.price-sale', '.price-promo', '.sale-price'],
        'regular' => ['.price', '.product-price', '[class*="price"]']
    ];
}

?>