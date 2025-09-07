<?php
/**
 * API Endpoint: /api/links.php
 * Obsługa linków produktów - dodawanie, pobieranie
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$user_id = ApiHelper::getUserId();

ApiHelper::checkRateLimit($user_id);
ApiHelper::logRequest('/api/links.php', $user_id, $method, $_POST);
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
    error_log("API Error in links.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'list':
            getLinksList($pdo, $user_id);
            break;
            
        case 'for_product':
            getLinksForProduct($pdo, $user_id);
            break;
            
        case 'by_shop':
            getLinksByShop($pdo, $user_id);
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
            addLink($pdo, $input, $user_id);
            break;
            
        case 'bulk_add':
            bulkAddLinks($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Pobiera listę wszystkich linków
 */
function getLinksList($pdo, $user_id) {
    $limit = min(intval($_GET['limit'] ?? 100), 1000);
    $offset = max(intval($_GET['offset'] ?? 0), 0);
    $shop_id = $_GET['shop_id'] ?? '';
    
    try {
        $where_clause = '';
        $params = [];
        
        if (!empty($shop_id)) {
            $where_clause = ' WHERE pl.shop_id = ?';
            $params[] = $shop_id;
        }
        
        $sql = "SELECT pl.id, pl.product_id, pl.shop_id, pl.url, pl.added_by, pl.created_at,
                       p.name as product_name, p.ean
                FROM product_links pl
                LEFT JOIN products p ON pl.product_id = p.id
                $where_clause
                ORDER BY pl.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $links = $stmt->fetchAll();
        
        // Policz total
        $count_sql = "SELECT COUNT(*) as total FROM product_links pl $where_clause";
        $count_params = !empty($shop_id) ? [$shop_id] : [];
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($count_params);
        $total = $stmt->fetch()['total'];
        
        ApiHelper::jsonResponse([
            'success' => true,
            'links' => $links,
            'pagination' => [
                'total' => intval($total),
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera linki dla konkretnego produktu
 */
function getLinksForProduct($pdo, $user_id) {
    $product_id = intval($_GET['product_id'] ?? 0);
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    try {
        $sql = "SELECT pl.id, pl.product_id, pl.shop_id, pl.url, pl.added_by, pl.created_at,
                       p.name as product_name,
                       sc.name as shop_name,
                       sc.delivery_cost, sc.delivery_free_from
                FROM product_links pl
                LEFT JOIN products p ON pl.product_id = p.id
                LEFT JOIN shop_configs sc ON pl.shop_id = sc.shop_id
                WHERE pl.product_id = ?
                ORDER BY pl.shop_id ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$product_id]);
        $links = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'product_id' => $product_id,
            'links' => $links,
            'count' => count($links)
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera linki pogrupowane po sklepach
 */
function getLinksByShop($pdo, $user_id) {
    try {
        $sql = "SELECT pl.shop_id, 
                       COUNT(*) as links_count,
                       sc.name as shop_name,
                       MAX(pl.created_at) as last_added
                FROM product_links pl
                LEFT JOIN shop_configs sc ON pl.shop_id = sc.shop_id
                GROUP BY pl.shop_id
                ORDER BY links_count DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $shops = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'shops' => $shops,
            'total_shops' => count($shops)
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Dodaje nowy link
 */
function addLink($pdo, $input, $user_id) {
    $product_id = intval($input['product_id'] ?? 0);
    $shop_id = trim($input['shop_id'] ?? '');
    $url = trim($input['url'] ?? '');
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    if (empty($shop_id)) {
        ApiHelper::jsonError('Shop ID is required');
    }
    
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        ApiHelper::jsonError('Valid URL is required');
    }
    
    try {
        // Sprawdź czy produkt istnieje
        $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        
        if ($stmt->rowCount() === 0) {
            ApiHelper::jsonError('Product not found', 404);
        }
        
        // Sprawdź czy link już istnieje dla tego produktu w tym sklepie
        $stmt = $pdo->prepare("SELECT id FROM product_links WHERE product_id = ? AND shop_id = ?");
        $stmt->execute([$product_id, $shop_id]);
        
        if ($stmt->rowCount() > 0) {
            $existing = $stmt->fetch();
            ApiHelper::jsonResponse([
                'success' => false,
                'error' => 'Link already exists for this product in this shop',
                'existing_id' => $existing['id']
            ], 409);
        }
        
        // Sprawdź czy dokładnie ten URL już istnieje
        $stmt = $pdo->prepare("SELECT id, product_id FROM product_links WHERE url = ?");
        $stmt->execute([$url]);
        
        if ($stmt->rowCount() > 0) {
            $existing = $stmt->fetch();
            ApiHelper::jsonResponse([
                'success' => false,
                'error' => 'This URL is already assigned to another product',
                'existing_product_id' => $existing['product_id']
            ], 409);
        }
        
        // Dodaj link
        $stmt = $pdo->prepare("INSERT INTO product_links (product_id, shop_id, url, added_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$product_id, $shop_id, $url, $user_id]);
        
        $link_id = $pdo->lastInsertId();
        
        // Zwiększ licznik contributions
        $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'link_id' => intval($link_id),
            'product_id' => $product_id,
            'shop_id' => $shop_id,
            'url' => $url,
            'message' => 'Link added successfully'
        ], 201);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to add link: ' . $e->getMessage());
    }
}

/**
 * Dodaje wiele linków naraz
 */
function bulkAddLinks($pdo, $input, $user_id) {
    $links = $input['links'] ?? [];
    
    if (empty($links) || !is_array($links)) {
        ApiHelper::jsonError('Links array is required');
    }
    
    if (count($links) > 200) {
        ApiHelper::jsonError('Maximum 200 links per batch');
    }
    
    $results = [];
    $added_count = 0;
    $skipped_count = 0;
    
    try {
        $pdo->beginTransaction();
        
        foreach ($links as $index => $link) {
            $product_id = intval($link['product_id'] ?? 0);
            $shop_id = trim($link['shop_id'] ?? '');
            $url = trim($link['url'] ?? '');
            $local_id = $link['local_id'] ?? $index;
            
            if ($product_id <= 0 || empty($shop_id) || empty($url)) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Missing required fields'
                ];
                $skipped_count++;
                continue;
            }
            
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Invalid URL'
                ];
                $skipped_count++;
                continue;
            }
            
            // Sprawdź czy produkt istnieje
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            
            if ($stmt->rowCount() === 0) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Product not found'
                ];
                $skipped_count++;
                continue;
            }
            
            // Sprawdź duplikaty
            $stmt = $pdo->prepare("SELECT id FROM product_links WHERE product_id = ? AND shop_id = ?");
            $stmt->execute([$product_id, $shop_id]);
            
            if ($stmt->rowCount() > 0) {
                $existing = $stmt->fetch();
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Link already exists for this product in this shop',
                    'existing_id' => $existing['id']
                ];
                $skipped_count++;
                continue;
            }
            
            // Sprawdź URL
            $stmt = $pdo->prepare("SELECT id, product_id FROM product_links WHERE url = ?");
            $stmt->execute([$url]);
            
            if ($stmt->rowCount() > 0) {
                $existing = $stmt->fetch();
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'URL already assigned to another product',
                    'existing_product_id' => $existing['product_id']
                ];
                $skipped_count++;
                continue;
            }
            
            // Dodaj link
            $stmt = $pdo->prepare("INSERT INTO product_links (product_id, shop_id, url, added_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$product_id, $shop_id, $url, $user_id]);
            
            $results[] = [
                'local_id' => $local_id,
                'success' => true,
                'link_id' => intval($pdo->lastInsertId()),
                'product_id' => $product_id,
                'shop_id' => $shop_id
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
                'total_processed' => count($links),
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