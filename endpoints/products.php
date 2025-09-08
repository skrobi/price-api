<?php
/**
 * API Endpoint: /endpoints/products.php
 * Obsługa produktów - dodawanie, pobieranie, sprawdzanie duplikatów
 */

require_once '../config.php';

// Ustawienia CORS
ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$user_id = ApiHelper::getUserId();

// Rate limiting
ApiHelper::checkRateLimit($user_id);

// Log request
ApiHelper::logRequest('/endpoints/products.php', $user_id, $method, $_POST);

// Ensure user exists
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
    error_log("API Error in endpoints/products.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

/**
 * Obsługa GET requests
 */
function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'list':
            getProductsList($pdo, $user_id);
            break;
            
        case 'search':
            searchProducts($pdo, $user_id);
            break;
            
        case 'check_duplicates':
            checkDuplicates($pdo, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Obsługa POST requests
 */
function handlePost($pdo, $action, $user_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        ApiHelper::jsonError('Invalid JSON input');
    }
    
    switch ($action) {
        case 'add':
            addProduct($pdo, $input, $user_id);
            break;
            
        case 'bulk_add':
            bulkAddProducts($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Pobiera listę produktów
 */
function getProductsList($pdo, $user_id) {
    $limit = min(intval($_GET['limit'] ?? 100), 1000);
    $offset = max(intval($_GET['offset'] ?? 0), 0);
    $search = $_GET['search'] ?? '';
    
    try {
        $where_clause = '';
        $params = [];
        
        if (!empty($search)) {
            $where_clause = ' WHERE name LIKE ? OR ean LIKE ?';
            $params = ["%$search%", "%$search%"];
        }
        
        // Główne zapytanie
        $sql = "SELECT id, name, ean, created_by, created_at 
                FROM products 
                $where_clause 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Policz total
        $count_sql = "SELECT COUNT(*) as total FROM products $where_clause";
        $count_params = !empty($search) ? ["%$search%", "%$search%"] : [];
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($count_params);
        $total = $stmt->fetch()['total'];
        
        ApiHelper::jsonResponse([
            'success' => true,
            'products' => $products,
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
 * Wyszukuje produkty po nazwie
 */
function searchProducts($pdo, $user_id) {
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        ApiHelper::jsonResponse([
            'success' => true,
            'products' => [],
            'message' => 'Query too short'
        ]);
    }
    
    try {
        $sql = "SELECT id, name, ean, created_at 
                FROM products 
                WHERE name LIKE ? OR ean = ?
                ORDER BY 
                    CASE WHEN name = ? THEN 1 ELSE 2 END,
                    name ASC
                LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$query%", $query, $query]);
        $products = $stmt->fetchAll();
        
        ApiHelper::jsonResponse([
            'success' => true,
            'products' => $products,
            'query' => $query
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Search error: ' . $e->getMessage());
    }
}

/**
 * Sprawdza czy produkt już istnieje (duplikaty)
 */
function checkDuplicates($pdo, $user_id) {
    $name = $_GET['name'] ?? '';
    $ean = $_GET['ean'] ?? '';
    
    if (empty($name)) {
        ApiHelper::jsonError('Product name is required');
    }
    
    try {
        $sql = "SELECT id, name, ean FROM products WHERE ";
        $params = [];
        
        if (!empty($ean)) {
            $sql .= "ean = ? OR ";
            $params[] = $ean;
        }
        
        $sql .= "name = ? OR SOUNDEX(name) = SOUNDEX(?)";
        $params[] = $name;
        $params[] = $name;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $duplicates = $stmt->fetchAll();
        
        $exists = count($duplicates) > 0;
        
        ApiHelper::jsonResponse([
            'success' => true,
            'exists' => $exists,
            'duplicates' => $duplicates,
            'checked_name' => $name,
            'checked_ean' => $ean
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Duplicate check error: ' . $e->getMessage());
    }
}

/**
 * Dodaje nowy produkt
 */
function addProduct($pdo, $input, $user_id) {
    $name = trim($input['name'] ?? '');
    $ean = trim($input['ean'] ?? '');
    
    if (empty($name)) {
        ApiHelper::jsonError('Product name is required');
    }
    
    if (strlen($name) < 3) {
        ApiHelper::jsonError('Product name too short (minimum 3 characters)');
    }
    
    if (strlen($name) > 255) {
        ApiHelper::jsonError('Product name too long (maximum 255 characters)');
    }
    
    try {
        // Sprawdź duplikaty
        $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? OR (ean = ? AND ean != '')");
        $stmt->execute([$name, $ean]);
        
        if ($stmt->rowCount() > 0) {
            $existing = $stmt->fetch();
            ApiHelper::jsonResponse([
                'success' => false,
                'error' => 'Product already exists',
                'existing_id' => $existing['id']
            ], 409);
        }
        
        // Dodaj produkt
        $stmt = $pdo->prepare("INSERT INTO products (name, ean, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$name, $ean, $user_id]);
        
        $product_id = $pdo->lastInsertId();
        
        // Zwiększ licznik contributions
        $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'product_id' => intval($product_id),
            'name' => $name,
            'ean' => $ean,
            'message' => 'Product added successfully'
        ], 201);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to add product: ' . $e->getMessage());
    }
}

/**
 * Dodaje wiele produktów naraz
 */
function bulkAddProducts($pdo, $input, $user_id) {
    $products = $input['products'] ?? [];
    
    if (empty($products) || !is_array($products)) {
        ApiHelper::jsonError('Products array is required');
    }
    
    if (count($products) > 100) {
        ApiHelper::jsonError('Maximum 100 products per batch');
    }
    
    $results = [];
    $added_count = 0;
    $skipped_count = 0;
    
    try {
        $pdo->beginTransaction();
        
        foreach ($products as $index => $product) {
            $name = trim($product['name'] ?? '');
            $ean = trim($product['ean'] ?? '');
            $local_id = $product['local_id'] ?? $index;
            
            if (empty($name)) {
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Name is required'
                ];
                $skipped_count++;
                continue;
            }
            
            // Sprawdź duplikaty
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? OR (ean = ? AND ean != '')");
            $stmt->execute([$name, $ean]);
            
            if ($stmt->rowCount() > 0) {
                $existing = $stmt->fetch();
                $results[] = [
                    'local_id' => $local_id,
                    'success' => false,
                    'error' => 'Already exists',
                    'existing_id' => $existing['id']
                ];
                $skipped_count++;
                continue;
            }
            
            // Dodaj produkt
            $stmt = $pdo->prepare("INSERT INTO products (name, ean, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$name, $ean, $user_id]);
            
            $results[] = [
                'local_id' => $local_id,
                'success' => true,
                'product_id' => intval($pdo->lastInsertId()),
                'name' => $name
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
                'total_processed' => count($products),
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