<?php
/**
 * API Endpoint: /endpoints/substitutes.php
 * Obsługa grup zamienników produktów - POPRAWIONE DLA MARIADB
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$user_id = ApiHelper::getUserId();

ApiHelper::checkRateLimit($user_id);
ApiHelper::logRequest('/endpoints/substitutes.php', $user_id, $method, $_POST);
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
            
        case 'DELETE':
            handleDelete($pdo, $action, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("API Error in endpoints/substitutes.php: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

function handleGet($pdo, $action, $user_id) {
    switch ($action) {
        case 'list':
            getSubstituteGroups($pdo, $user_id);
            break;
            
        case 'for_product':
            getSubstitutesForProduct($pdo, $user_id);
            break;
            
        case 'group':
            getSubstituteGroup($pdo, $user_id);
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
            addSubstituteGroup($pdo, $input, $user_id);
            break;
            
        case 'bulk_add':
            bulkAddSubstituteGroups($pdo, $input, $user_id);
            break;
            
        case 'update':
            updateSubstituteGroup($pdo, $input, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

function handleDelete($pdo, $action, $user_id) {
    switch ($action) {
        case 'group':
            deleteSubstituteGroup($pdo, $user_id);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Pobiera wszystkie grupy zamienników - POPRAWIONE DLA MARIADB
 */
function getSubstituteGroups($pdo, $user_id) {
    $limit = min(intval($_GET['limit'] ?? 100), 500);
    $offset = max(intval($_GET['offset'] ?? 0), 0);
    
    try {
        // POPRAWKA: Uproszczone zapytanie bez JSON_CONTAINS
        $sql = "SELECT sg.group_id, sg.name, sg.product_ids, sg.priority_map, sg.settings, 
                       sg.created_by, sg.created_at
                FROM substitute_groups sg
                ORDER BY sg.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $groups = $stmt->fetchAll();
        
        // Dekoduj JSON fields i dodaj nazwy produktów
        foreach ($groups as &$group) {
            $group['product_ids'] = json_decode($group['product_ids'], true);
            $group['priority_map'] = json_decode($group['priority_map'], true);
            $group['settings'] = json_decode($group['settings'], true);
            
            // Pobierz nazwy produktów osobno
            if (!empty($group['product_ids'])) {
                $placeholders = str_repeat('?,', count($group['product_ids']) - 1) . '?';
                $stmt2 = $pdo->prepare("SELECT name FROM products WHERE id IN ($placeholders)");
                $stmt2->execute($group['product_ids']);
                $names = $stmt2->fetchAll(PDO::FETCH_COLUMN);
                $group['product_names'] = implode(', ', $names);
            } else {
                $group['product_names'] = '';
            }
        }
        
        // Policz total
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM substitute_groups");
        $total = $stmt->fetch()['total'];
        
        ApiHelper::jsonResponse([
            'success' => true,
            'groups' => $groups,
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
 * Pobiera zamienniki dla konkretnego produktu - POPRAWIONE DLA MARIADB
 */
function getSubstitutesForProduct($pdo, $user_id) {
    $product_id = intval($_GET['product_id'] ?? 0);
    
    if ($product_id <= 0) {
        ApiHelper::jsonError('Valid product_id is required');
    }
    
    try {
        // POPRAWKA: Używaj JSON_SEARCH zamiast JSON_CONTAINS dla MariaDB
        $sql = "SELECT sg.group_id, sg.name, sg.product_ids, sg.priority_map, sg.settings, sg.created_at
                FROM substitute_groups sg
                WHERE JSON_SEARCH(sg.product_ids, 'one', ?) IS NOT NULL";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$product_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            ApiHelper::jsonResponse([
                'success' => true,
                'product_id' => $product_id,
                'has_substitutes' => false,
                'group' => null,
                'substitutes' => []
            ]);
            return;
        }
        
        // Dekoduj dane grupy
        $product_ids = json_decode($group['product_ids'], true);
        $priority_map = json_decode($group['priority_map'], true);
        $settings = json_decode($group['settings'], true);
        
        // Pobierz wszystkie produkty z grupy oprócz bieżącego
        $substitute_ids = array_filter($product_ids, function($id) use ($product_id) {
            return $id != $product_id;
        });
        
        $substitutes = [];
        if (!empty($substitute_ids)) {
            $placeholders = str_repeat('?,', count($substitute_ids) - 1) . '?';
            $sql = "SELECT id, name, ean FROM products WHERE id IN ($placeholders)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($substitute_ids);
            $substitutes = $stmt->fetchAll();
            
            // Dodaj priorytety
            foreach ($substitutes as &$substitute) {
                $substitute['priority'] = $priority_map[$substitute['id']] ?? 99;
            }
            
            // Sortuj według priorytetu
            usort($substitutes, function($a, $b) {
                return $a['priority'] - $b['priority'];
            });
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'product_id' => $product_id,
            'has_substitutes' => !empty($substitutes),
            'group' => [
                'group_id' => $group['group_id'],
                'name' => $group['name'],
                'settings' => $settings,
                'created_at' => $group['created_at']
            ],
            'substitutes' => $substitutes
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Pobiera szczegóły konkretnej grupy zamienników
 */
function getSubstituteGroup($pdo, $user_id) {
    $group_id = $_GET['group_id'] ?? '';
    
    if (empty($group_id)) {
        ApiHelper::jsonError('Group ID is required');
    }
    
    try {
        $sql = "SELECT sg.group_id, sg.name, sg.product_ids, sg.priority_map, sg.settings, 
                       sg.created_by, sg.created_at
                FROM substitute_groups sg
                WHERE sg.group_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            ApiHelper::jsonError('Group not found', 404);
        }
        
        // Dekoduj JSON
        $product_ids = json_decode($group['product_ids'], true);
        $priority_map = json_decode($group['priority_map'], true);
        $settings = json_decode($group['settings'], true);
        
        // Pobierz szczegóły produktów
        $products = [];
        if (!empty($product_ids)) {
            $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
            $sql = "SELECT id, name, ean FROM products WHERE id IN ($placeholders)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($product_ids);
            $products = $stmt->fetchAll();
            
            // Dodaj priorytety
            foreach ($products as &$product) {
                $product['priority'] = $priority_map[$product['id']] ?? 99;
            }
            
            // Sortuj według priorytetu
            usort($products, function($a, $b) {
                return $a['priority'] - $b['priority'];
            });
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'group' => [
                'group_id' => $group['group_id'],
                'name' => $group['name'],
                'settings' => $settings,
                'created_by' => $group['created_by'],
                'created_at' => $group['created_at'],
                'products' => $products,
                'product_count' => count($products)
            ]
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Database error: ' . $e->getMessage());
    }
}

/**
 * Dodaje nową grupę zamienników - POPRAWIONE DLA MARIADB
 */
function addSubstituteGroup($pdo, $input, $user_id) {
    $name = trim($input['name'] ?? '');
    $product_ids = $input['product_ids'] ?? [];
    $priority_map = $input['priority_map'] ?? [];
    $settings = $input['settings'] ?? [];
    
    if (empty($name)) {
        ApiHelper::jsonError('Group name is required');
    }
    
    if (count($product_ids) < 2) {
        ApiHelper::jsonError('At least 2 products are required for a substitute group');
    }
    
    // Walidacja product_ids
    foreach ($product_ids as $pid) {
        if (!is_int($pid) || $pid <= 0) {
            ApiHelper::jsonError('All product IDs must be positive integers');
        }
    }
    
    try {
        // Sprawdź czy wszystkie produkty istnieją
        $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE id IN ($placeholders)");
        $stmt->execute($product_ids);
        $existing_count = $stmt->fetch()['count'];
        
        if ($existing_count != count($product_ids)) {
            ApiHelper::jsonError('One or more products not found');
        }
        
        // POPRAWKA: Sprawdź czy któryś z produktów nie jest już w innej grupie (MariaDB)
        foreach ($product_ids as $pid) {
            $stmt = $pdo->prepare("SELECT group_id FROM substitute_groups WHERE JSON_SEARCH(product_ids, 'one', ?) IS NOT NULL");
            $stmt->execute([$pid]);
            
            if ($stmt->rowCount() > 0) {
                $existing_group = $stmt->fetch();
                ApiHelper::jsonError("Product $pid is already in group: " . $existing_group['group_id']);
            }
        }
        
        // Wygeneruj group_id
        $group_id = 'group_' . uniqid() . '_' . time();
        
        // Domyślne ustawienia
        $default_settings = [
            'max_price_increase_percent' => 20.0,
            'min_quantity_ratio' => 0.8,
            'max_quantity_ratio' => 1.5,
            'allow_automatic_substitution' => true
        ];
        $final_settings = array_merge($default_settings, $settings);
        
        // Domyślne priorytety (wszyscy równi)
        if (empty($priority_map)) {
            foreach ($product_ids as $pid) {
                $priority_map[$pid] = 1;
            }
        }
        
        // Dodaj grupę
        $stmt = $pdo->prepare("INSERT INTO substitute_groups (group_id, name, product_ids, priority_map, settings, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $group_id,
            $name,
            json_encode($product_ids, JSON_UNESCAPED_UNICODE),
            json_encode($priority_map, JSON_UNESCAPED_UNICODE),
            json_encode($final_settings, JSON_UNESCAPED_UNICODE),
            $user_id
        ]);
        
        // Zwiększ licznik contributions
        $stmt = $pdo->prepare("UPDATE users SET contributions_count = contributions_count + 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'group_id' => $group_id,
            'name' => $name,
            'product_count' => count($product_ids),
            'message' => 'Substitute group created successfully'
        ], 201);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to create substitute group: ' . $e->getMessage());
    }
}

/**
 * Dodaje wiele grup zamienników naraz
 */
function bulkAddSubstituteGroups($pdo, $input, $user_id) {
    $groups = $input['groups'] ?? [];
    
    if (empty($groups) || !is_array($groups)) {
        ApiHelper::jsonError('Groups array is required');
    }
    
    if (count($groups) > 20) {
        ApiHelper::jsonError('Maximum 20 groups per batch');
    }
    
    $results = [];
    $added_count = 0;
    $skipped_count = 0;
    
    try {
        $pdo->beginTransaction();
        
        foreach ($groups as $index => $group) {
            $name = trim($group['name'] ?? '');
            $product_ids = $group['product_ids'] ?? [];
            
            if (empty($name) || count($product_ids) < 2) {
                $results[] = [
                    'index' => $index,
                    'success' => false,
                    'error' => 'Invalid group data'
                ];
                $skipped_count++;
                continue;
            }
            
            // Sprawdź duplikaty w tej grupie
            $stmt = $pdo->prepare("SELECT group_id FROM substitute_groups WHERE name = ?");
            $stmt->execute([$name]);
            
            if ($stmt->rowCount() > 0) {
                $results[] = [
                    'index' => $index,
                    'success' => false,
                    'error' => 'Group name already exists'
                ];
                $skipped_count++;
                continue;
            }
            
            // Wygeneruj group_id
            $group_id = 'group_' . uniqid() . '_' . time();
            
            // Domyślne ustawienia i priorytety
            $priority_map = [];
            foreach ($product_ids as $pid) {
                $priority_map[$pid] = 1;
            }
            
            $default_settings = [
                'max_price_increase_percent' => 20.0,
                'min_quantity_ratio' => 0.8,
                'max_quantity_ratio' => 1.5,
                'allow_automatic_substitution' => true
            ];
            
            // Dodaj grupę
            $stmt = $pdo->prepare("INSERT INTO substitute_groups (group_id, name, product_ids, priority_map, settings, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $group_id,
                $name,
                json_encode($product_ids, JSON_UNESCAPED_UNICODE),
                json_encode($priority_map, JSON_UNESCAPED_UNICODE),
                json_encode($default_settings, JSON_UNESCAPED_UNICODE),
                $user_id
            ]);
            
            $results[] = [
                'index' => $index,
                'success' => true,
                'group_id' => $group_id,
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
                'total_processed' => count($groups),
                'added' => $added_count,
                'skipped' => $skipped_count
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        ApiHelper::jsonError('Bulk add failed: ' . $e->getMessage());
    }
}

/**
 * Usuwa grupę zamienników
 */
function deleteSubstituteGroup($pdo, $user_id) {
    $group_id = $_GET['group_id'] ?? '';
    
    if (empty($group_id)) {
        ApiHelper::jsonError('Group ID is required');
    }
    
    try {
        // Sprawdź czy grupa istnieje
        $stmt = $pdo->prepare("SELECT created_by FROM substitute_groups WHERE group_id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            ApiHelper::jsonError('Group not found', 404);
        }
        
        // Usuń grupę
        $stmt = $pdo->prepare("DELETE FROM substitute_groups WHERE group_id = ?");
        $stmt->execute([$group_id]);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'group_id' => $group_id,
            'message' => 'Substitute group deleted successfully'
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Failed to delete group: ' . $e->getMessage());
    }
}

?>