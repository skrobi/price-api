<?php
/**
 * API Endpoint: /endpoints/sync.php
 * Synchronizacja - sprawdza stan synchronizacji i oferuje bulk sync - POPRAWIONE DLA MARIADB
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';
$user_id = ApiHelper::getUserId();

ApiHelper::checkRateLimit($user_id);
ApiHelper::logRequest('/endpoints/sync.php', $user_id, $method, $_POST);
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
    error_log("API Error in endpoints/sync.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'status':
            getSyncStatus($pdo, $user_id);
            break;
            
        case 'summary':
            getSyncSummary($pdo, $user_id);
            break;
            
        case 'changes':
            getRecentChanges($pdo, $user_id);
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
        case 'full_sync':
            performFullSync($pdo, $input, $user_id);
            break;
            
        case 'upload_batch':
            uploadDataBatch($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Status synchronizacji dla użytkownika
 */
function getSyncStatus($pdo, $user_id) {
    try {
        // Sprawdź ostatnią aktywność użytkownika
        $stmt = $pdo->prepare("SELECT last_active, contributions_count FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
        
        if (!$user_info) {
            ApiHelper::jsonError('User not found', 404);
        }
        
        // Policz wkład użytkownika
        $contributions = [];
        
        // Produkty
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE created_by = ?");
        $stmt->execute([$user_id]);
        $contributions['products'] = intval($stmt->fetch()['count']);
        
        // Linki
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM product_links WHERE added_by = ?");
        $stmt->execute([$user_id]);
        $contributions['links'] = intval($stmt->fetch()['count']);
        
        // Ceny
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM prices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $contributions['prices'] = intval($stmt->fetch()['count']);
        
        // Konfiguracje sklepów
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shop_configs WHERE updated_by = ?");
        $stmt->execute([$user_id]);
        $contributions['shop_configs'] = intval($stmt->fetch()['count']);
        
        // Grupy zamienników
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM substitute_groups WHERE created_by = ?");
        $stmt->execute([$user_id]);
        $contributions['substitute_groups'] = intval($stmt->fetch()['count']);
        
        // Ostatnie aktywności
        $recent_activities = [];
        
        // Ostatnie ceny
        $stmt = $pdo->prepare("
            SELECT 'price' as type, product_id as entity_id, created_at 
            FROM prices 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $recent_activities = array_merge($recent_activities, $stmt->fetchAll());
        
        // Ostatnie produkty
        $stmt = $pdo->prepare("
            SELECT 'product' as type, id as entity_id, created_at 
            FROM products 
            WHERE created_by = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $recent_activities = array_merge($recent_activities, $stmt->fetchAll());
        
        // Sortuj po dacie
        usort($recent_activities, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        $recent_activities = array_slice($recent_activities, 0, 10);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'user_id' => $user_id,
            'last_active' => $user_info['last_active'],
            'total_contributions' => intval($user_info['contributions_count']),
            'contributions_breakdown' => $contributions,
            'recent_activities' => $recent_activities,
            'sync_timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to get sync status: ' . $e->getMessage());
    }
}

/**
 * Podsumowanie stanu bazy danych
 */
function getSyncSummary($pdo, $user_id) {
    try {
        $summary = [];
        
        // Podstawowe statystyki
        $tables = ['users', 'products', 'product_links', 'prices', 'shop_configs', 'substitute_groups'];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $summary[$table] = intval($stmt->fetch()['count']);
        }
        
        // Najnowsze wpisy
        $stmt = $pdo->query("SELECT created_at FROM products ORDER BY created_at DESC LIMIT 1");
        $latest_product = $stmt->fetch();
        $summary['latest_product_date'] = $latest_product ? $latest_product['created_at'] : null;
        
        $stmt = $pdo->query("SELECT created_at FROM prices ORDER BY created_at DESC LIMIT 1");
        $latest_price = $stmt->fetch();
        $summary['latest_price_date'] = $latest_price ? $latest_price['created_at'] : null;
        
        // POPRAWKA MARIADB: Użyj DATE_ADD zamiast DATE_SUB
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE last_active >= DATE_ADD(NOW(), INTERVAL -30 DAY)");
        $summary['active_users_30d'] = intval($stmt->fetch()['count']);
        
        // Sklepy z danymi
        $stmt = $pdo->query("
            SELECT sc.shop_id, sc.name, 
                   COUNT(DISTINCT pl.product_id) as products_count,
                   COUNT(DISTINCT pr.id) as prices_count
            FROM shop_configs sc
            LEFT JOIN product_links pl ON sc.shop_id = pl.shop_id
            LEFT JOIN prices pr ON sc.shop_id = pr.shop_id
            GROUP BY sc.shop_id, sc.name
            ORDER BY products_count DESC
        ");
        $summary['shops_data'] = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'summary' => $summary,
            'generated_at' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to get sync summary: ' . $e->getMessage());
    }
}

/**
 * Ostatnie zmiany w bazie - POPRAWIONE DLA MARIADB
 */
function getRecentChanges($pdo, $user_id) {
    $hours = min(intval($_GET['hours'] ?? 24), 168); // Max 7 dni
    
    try {
        $changes = [];
        
        // POPRAWKA MARIADB: Użyj DATE_ADD zamiast DATE_SUB
        // Ostatnie produkty
        $stmt = $pdo->prepare("
            SELECT 'product' as type, id as entity_id, name as entity_name, 
                   created_by as user_id, created_at as timestamp
            FROM products 
            WHERE created_at >= DATE_ADD(NOW(), INTERVAL -? HOUR)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$hours]);
        $changes = array_merge($changes, $stmt->fetchAll());
        
        // Ostatnie linki
        $stmt = $pdo->prepare("
            SELECT 'link' as type, pl.id as entity_id, 
                   CONCAT(p.name, ' -> ', pl.shop_id) as entity_name,
                   pl.added_by as user_id, pl.created_at as timestamp
            FROM product_links pl
            LEFT JOIN products p ON pl.product_id = p.id
            WHERE pl.created_at >= DATE_ADD(NOW(), INTERVAL -? HOUR)
            ORDER BY pl.created_at DESC
        ");
        $stmt->execute([$hours]);
        $changes = array_merge($changes, $stmt->fetchAll());
        
        // Ostatnie ceny
        $stmt = $pdo->prepare("
            SELECT 'price' as type, pr.id as entity_id,
                   CONCAT(p.name, ' (', pr.shop_id, '): ', pr.price, ' ', pr.currency) as entity_name,
                   pr.user_id, pr.created_at as timestamp
            FROM prices pr
            LEFT JOIN products p ON pr.product_id = p.id
            WHERE pr.created_at >= DATE_ADD(NOW(), INTERVAL -? HOUR)
            ORDER BY pr.created_at DESC
        ");
        $stmt->execute([$hours]);
        $changes = array_merge($changes, $stmt->fetchAll());
        
        // Sortuj wszystko po czasie
        usort($changes, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        $changes = array_slice($changes, 0, 50); // Maksymalnie 50 zmian
        
        ApiHelper::jsonResponse([
            'success' => true,
            'hours' => $hours,
            'changes_count' => count($changes),
            'changes' => $changes,
            'generated_at' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to get recent changes: ' . $e->getMessage());
    }
}

/**
 * Pełna synchronizacja - wysyła wszystkie dane z klienta
 */
function performFullSync($pdo, $input, $user_id) {
    $products = $input['products'] ?? [];
    $links = $input['links'] ?? [];
    $prices = $input['prices'] ?? [];
    $shop_configs = $input['shop_configs'] ?? [];
    $substitute_groups = $input['substitute_groups'] ?? [];
    
    $results = [
        'products' => ['added' => 0, 'skipped' => 0, 'errors' => []],
        'links' => ['added' => 0, 'skipped' => 0, 'errors' => []],
        'prices' => ['added' => 0, 'skipped' => 0, 'errors' => []],
        'shop_configs' => ['added' => 0, 'updated' => 0, 'errors' => []],
        'substitute_groups' => ['added' => 0, 'skipped' => 0, 'errors' => []]
    ];
    
    try {
        $pdo->beginTransaction();
        
        // Produkty
        if (!empty($products)) {
            foreach ($products as $product) {
                try {
                    // Sprawdź duplikaty
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ?");
                    $stmt->execute([$product['name']]);
                    
                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO products (name, ean, created_by) VALUES (?, ?, ?)");
                        $stmt->execute([
                            $product['name'], 
                            $product['ean'] ?? '', 
                            $user_id
                        ]);
                        $results['products']['added']++;
                    } else {
                        $results['products']['skipped']++;
                    }
                } catch (Exception $e) {
                    $results['products']['errors'][] = $e->getMessage();
                }
            }
        }
        
        // Linki (uproszczone - tylko sprawdzenie duplikatów)
        if (!empty($links)) {
            foreach ($links as $link) {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM product_links WHERE product_id = ? AND shop_id = ?");
                    $stmt->execute([$link['product_id'], $link['shop_id']]);
                    
                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare("INSERT INTO product_links (product_id, shop_id, url, added_by) VALUES (?, ?, ?, ?)");
                        $stmt->execute([
                            $link['product_id'],
                            $link['shop_id'],
                            $link['url'],
                            $user_id
                        ]);
                        $results['links']['added']++;
                    } else {
                        $results['links']['skipped']++;
                    }
                } catch (Exception $e) {
                    $results['links']['errors'][] = $e->getMessage();
                }
            }
        }
        
        // Ceny (tylko ostatnie 100 dla każdego produktu/sklepu)
        if (!empty($prices)) {
            $price_batch = array_slice($prices, -100); // Tylko ostatnie 100
            
            foreach ($price_batch as $price) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO prices (product_id, shop_id, price, currency, price_type, url, user_id, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $price['product_id'],
                        $price['shop_id'],
                        $price['price'],
                        $price['currency'] ?? 'PLN',
                        $price['price_type'] ?? 'sync',
                        $price['url'] ?? '',
                        $user_id,
                        'full_sync'
                    ]);
                    $results['prices']['added']++;
                } catch (Exception $e) {
                    $results['prices']['errors'][] = $e->getMessage();
                }
            }
        }
        
        $pdo->commit();
        
        // Zaktualizuj licznik contributions
        $total_added = $results['products']['added'] + $results['links']['added'] + $results['prices']['added'];
        if ($total_added > 0) {
            $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + ? WHERE user_id = ?");
            $stmt->execute([$total_added, $user_id]);
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'sync_type' => 'full_sync',
            'results' => $results,
            'total_processed' => [
                'products' => count($products),
                'links' => count($links),
                'prices' => count($prices),
                'shop_configs' => count($shop_configs),
                'substitute_groups' => count($substitute_groups)
            ],
            'total_added' => $total_added,
            'completed_at' => date('c')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        ApiHelper::jsonError('Full sync failed: ' . $e->getMessage());
    }
}

/**
 * Upload pojedynczego batch'a danych
 */
function uploadDataBatch($pdo, $input, $user_id) {
    $data_type = $input['type'] ?? '';
    $data = $input['data'] ?? [];
    
    if (empty($data_type) || empty($data)) {
        ApiHelper::jsonError('Data type and data array are required');
    }
    
    try {
        switch ($data_type) {
            case 'products':
                $result = uploadProducts($pdo, $data, $user_id);
                break;
                
            case 'links':
                $result = uploadLinks($pdo, $data, $user_id);
                break;
                
            case 'prices':
                $result = uploadPrices($pdo, $data, $user_id);
                break;
                
            case 'shop_configs':
                $result = uploadShopConfigs($pdo, $data, $user_id);
                break;
                
            default:
                ApiHelper::jsonError('Unknown data type: ' . $data_type);
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'data_type' => $data_type,
            'result' => $result,
            'uploaded_at' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Batch upload failed: ' . $e->getMessage());
    }
}

/**
 * Upload produktów
 */
function uploadProducts($pdo, $products, $user_id) {
    $added = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($products as $product) {
        try {
            // Sprawdź duplikaty
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? OR (ean = ? AND ean != '')");
            $stmt->execute([$product['name'], $product['ean'] ?? '']);
            
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("INSERT INTO products (name, ean, created_by) VALUES (?, ?, ?)");
                $stmt->execute([
                    $product['name'],
                    $product['ean'] ?? '',
                    $user_id
                ]);
                $added++;
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            $errors[] = "Product '{$product['name']}': " . $e->getMessage();
        }
    }
    
    return [
        'added' => $added,
        'skipped' => $skipped,
        'errors' => $errors,
        'total_processed' => count($products)
    ];
}

/**
 * Upload linków
 */
function uploadLinks($pdo, $links, $user_id) {
    $added = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($links as $link) {
        try {
            // Sprawdź czy produkt istnieje
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$link['product_id']]);
            
            if ($stmt->rowCount() === 0) {
                $errors[] = "Product ID {$link['product_id']} not found";
                continue;
            }
            
            // Sprawdź duplikaty
            $stmt = $pdo->prepare("SELECT id FROM product_links WHERE product_id = ? AND shop_id = ?");
            $stmt->execute([$link['product_id'], $link['shop_id']]);
            
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("INSERT INTO product_links (product_id, shop_id, url, added_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $link['product_id'],
                    $link['shop_id'],
                    $link['url'],
                    $user_id
                ]);
                $added++;
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            $errors[] = "Link {$link['product_id']}-{$link['shop_id']}: " . $e->getMessage();
        }
    }
    
    return [
        'added' => $added,
        'skipped' => $skipped,
        'errors' => $errors,
        'total_processed' => count($links)
    ];
}

/**
 * Upload cen
 */
function uploadPrices($pdo, $prices, $user_id) {
    $added = 0;
    $errors = [];
    
    foreach ($prices as $price) {
        try {
            // Sprawdź czy produkt istnieje
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$price['product_id']]);
            
            if ($stmt->rowCount() === 0) {
                $errors[] = "Product ID {$price['product_id']} not found";
                continue;
            }
            
            $stmt = $pdo->prepare("INSERT INTO prices (product_id, shop_id, price, currency, price_type, url, user_id, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $price['product_id'],
                $price['shop_id'],
                $price['price'],
                $price['currency'] ?? 'PLN',
                $price['price_type'] ?? 'batch_upload',
                $price['url'] ?? '',
                $user_id,
                'batch_upload'
            ]);
            $added++;
            
        } catch (Exception $e) {
            $errors[] = "Price {$price['product_id']}-{$price['shop_id']}: " . $e->getMessage();
        }
    }
    
    return [
        'added' => $added,
        'errors' => $errors,
        'total_processed' => count($prices)
    ];
}

/**
 * Upload konfiguracji sklepów
 */
function uploadShopConfigs($pdo, $configs, $user_id) {
    $added = 0;
    $updated = 0;
    $errors = [];
    
    foreach ($configs as $config) {
        try {
            // Sprawdź czy istnieje
            $stmt = $pdo->prepare("SELECT shop_id FROM shop_configs WHERE shop_id = ?");
            $stmt->execute([$config['shop_id']]);
            $exists = $stmt->rowCount() > 0;
            
            if ($exists) {
                // Update
                $stmt = $pdo->prepare("UPDATE shop_configs SET name = ?, price_selectors = ?, delivery_free_from = ?, delivery_cost = ?, currency = ?, search_config = ?, updated_by = ?, updated_at = NOW() WHERE shop_id = ?");
                $stmt->execute([
                    $config['name'] ?? $config['shop_id'],
                    json_encode($config['price_selectors'] ?? [], JSON_UNESCAPED_UNICODE),
                    $config['delivery_free_from'] ?? null,
                    $config['delivery_cost'] ?? null,
                    $config['currency'] ?? 'PLN',
                    json_encode($config['search_config'] ?? [], JSON_UNESCAPED_UNICODE),
                    $user_id,
                    $config['shop_id']
                ]);
                $updated++;
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO shop_configs (shop_id, name, price_selectors, delivery_free_from, delivery_cost, currency, search_config, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $config['shop_id'],
                    $config['name'] ?? $config['shop_id'],
                    json_encode($config['price_selectors'] ?? [], JSON_UNESCAPED_UNICODE),
                    $config['delivery_free_from'] ?? null,
                    $config['delivery_cost'] ?? null,
                    $config['currency'] ?? 'PLN',
                    json_encode($config['search_config'] ?? [], JSON_UNESCAPED_UNICODE),
                    $user_id
                ]);
                $added++;
            }
            
        } catch (Exception $e) {
            $errors[] = "Config {$config['shop_id']}: " . $e->getMessage();
        }
    }
    
    return [
        'added' => $added,
        'updated' => $updated,
        'errors' => $errors,
        'total_processed' => count($configs)
    ];
}

?>