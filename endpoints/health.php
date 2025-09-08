<?php
/**
 * API Endpoint: /endpoints/health.php
 * Health check - sprawdza status API, bazy danych i podstawowych funkcji - POPRAWIONE DLA MARIADB
 */

require_once '../config.php';

ApiHelper::setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';

// Health check nie wymaga user_id i rate limiting
try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    switch ($method) {
        case 'GET':
            handleGet($pdo, $action);
            break;
            
        default:
            ApiHelper::jsonError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    // W przypadku błędu połączenia z bazą
    ApiHelper::jsonResponse([
        'success' => false,
        'status' => 'CRITICAL',
        'error' => 'Database connection failed',
        'timestamp' => date('c'),
        'checks' => [
            'database' => [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ]
        ]
    ], 503);
}

/**
 * Obsługa GET requests
 */
function handleGet($pdo, $action) {
    switch ($action) {
        case 'status':
            checkHealth($pdo);
            break;
            
        case 'version':
            checkVersion($pdo);
            break;
            
        case 'detailed':
            detailedHealth($pdo);
            break;
            
        case 'database':
            checkDatabaseOnly($pdo);
            break;
            
        default:
            ApiHelper::jsonError('Unknown action');
    }
}

/**
 * Podstawowy health check - POPRAWIONY DLA MARIADB
 */
function checkHealth($pdo) {
    $start_time = microtime(true);
    $checks = [];
    $overall_status = 'OK';
    
    // 1. Sprawdź połączenie z bazą danych
    try {
        $stmt = $pdo->query('SELECT 1');
        $checks['database'] = [
            'status' => 'OK',
            'message' => 'Database connection successful'
        ];
    } catch (Exception $e) {
        $checks['database'] = [
            'status' => 'FAIL',
            'error' => $e->getMessage()
        ];
        $overall_status = 'CRITICAL';
    }
    
    // 2. Sprawdź podstawowe tabele
    if ($overall_status !== 'CRITICAL') {
        try {
            $tables = ['users', 'products', 'product_links', 'prices', 'shop_configs'];
            $table_counts = [];
            
            foreach ($tables as $table) {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $stmt->fetch()['count'];
                $table_counts[$table] = intval($count);
            }
            
            $checks['tables'] = [
                'status' => 'OK',
                'counts' => $table_counts
            ];
            
            // Sprawdź czy mamy podstawowe dane
            if ($table_counts['products'] == 0) {
                $checks['data_integrity'] = [
                    'status' => 'WARNING',
                    'message' => 'No products in database'
                ];
                if ($overall_status === 'OK') $overall_status = 'WARNING';
            } else {
                $checks['data_integrity'] = [
                    'status' => 'OK',
                    'message' => 'Basic data present'
                ];
            }
            
        } catch (Exception $e) {
            $checks['tables'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
            $overall_status = 'CRITICAL';
        }
    }
    
    // 3. POPRAWKA MARIADB: Sprawdź widok latest_prices bezpiecznie
    if ($overall_status !== 'CRITICAL') {
        try {
            // Sprawdź czy view istnieje
            $stmt = $pdo->query('SHOW TABLES LIKE "latest_prices"');
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query('SELECT COUNT(*) as count FROM latest_prices');
                $latest_prices_count = $stmt->fetch()['count'];
                
                $checks['views'] = [
                    'status' => 'OK',
                    'latest_prices_count' => intval($latest_prices_count)
                ];
            } else {
                $checks['views'] = [
                    'status' => 'WARNING',
                    'message' => 'latest_prices view not found'
                ];
                if ($overall_status === 'OK') $overall_status = 'WARNING';
            }
        } catch (Exception $e) {
            $checks['views'] = [
                'status' => 'FAIL',
                'error' => $e->getMessage()
            ];
            if ($overall_status === 'OK') $overall_status = 'WARNING';
        }
    }
    
    // 4. POPRAWKA MARIADB: Test JSON support
    if ($overall_status !== 'CRITICAL') {
        try {
            $stmt = $pdo->query("SELECT JSON_VALID('[1,2,3]') as json_test");
            $json_result = $stmt->fetch()['json_test'];
            
            if ($json_result == 1) {
                $checks['json_support'] = [
                    'status' => 'OK',
                    'message' => 'MariaDB JSON functions available'
                ];
            } else {
                $checks['json_support'] = [
                    'status' => 'WARNING',
                    'message' => 'Limited JSON support detected'
                ];
                if ($overall_status === 'OK') $overall_status = 'WARNING';
            }
        } catch (Exception $e) {
            $checks['json_support'] = [
                'status' => 'FAIL',
                'error' => 'JSON functions not available: ' . $e->getMessage()
            ];
            if ($overall_status === 'OK') $overall_status = 'WARNING';
        }
    }
    
    // 5. Sprawdź uprawnienia do zapisu (logi)
    try {
        $log_test_file = 'logs/health_check_' . date('Y-m-d') . '.test';
        $test_content = "Health check test - " . date('c');
        
        if (file_put_contents($log_test_file, $test_content) !== false) {
            unlink($log_test_file); // Usuń test file
            $checks['filesystem'] = [
                'status' => 'OK',
                'message' => 'Log directory writable'
            ];
        } else {
            $checks['filesystem'] = [
                'status' => 'WARNING',
                'message' => 'Cannot write to logs directory'
            ];
            if ($overall_status === 'OK') $overall_status = 'WARNING';
        }
    } catch (Exception $e) {
        $checks['filesystem'] = [
            'status' => 'WARNING',
            'error' => $e->getMessage()
        ];
        if ($overall_status === 'OK') $overall_status = 'WARNING';
    }
    
    // 6. Sprawdź rate limiting
    try {
        $rate_test_file = '/tmp/rate_limit_health_test';
        if (file_put_contents($rate_test_file, time()) !== false) {
            unlink($rate_test_file);
            $checks['rate_limiting'] = [
                'status' => 'OK',
                'message' => 'Rate limiting storage accessible'
            ];
        } else {
            $checks['rate_limiting'] = [
                'status' => 'WARNING',
                'message' => 'Rate limiting storage not writable'
            ];
            if ($overall_status === 'OK') $overall_status = 'WARNING';
        }
    } catch (Exception $e) {
        $checks['rate_limiting'] = [
            'status' => 'WARNING',
            'error' => $e->getMessage()
        ];
        if ($overall_status === 'OK') $overall_status = 'WARNING';
    }
    
    $response_time = round((microtime(true) - $start_time) * 1000, 2);
    
    $response = [
        'success' => $overall_status !== 'CRITICAL',
        'status' => $overall_status,
        'timestamp' => date('c'),
        'response_time_ms' => $response_time,
        'api_version' => defined('API_VERSION') ? API_VERSION : '1.0',
        'checks' => $checks
    ];
    
    $http_code = ($overall_status === 'CRITICAL') ? 503 : 200;
    ApiHelper::jsonResponse($response, $http_code);
}

/**
 * Informacje o wersji - POPRAWIONE DLA MARIADB
 */
function checkVersion($pdo) {
    try {
        // Sprawdź wersję bazy danych
        $stmt = $pdo->query('SELECT VERSION() as version_info');
        $version_info = $stmt->fetch()['version_info'];
        
        // Sprawdź czy to MariaDB czy MySQL
        $is_mariadb = strpos(strtolower($version_info), 'mariadb') !== false;
        
        // Sprawdź wersję PHP
        $php_version = phpversion();
        
        // Sprawdź rozszerzenia PHP
        $required_extensions = ['pdo', 'pdo_mysql', 'json', 'curl'];
        $extensions_status = [];
        
        foreach ($required_extensions as $ext) {
            $extensions_status[$ext] = extension_loaded($ext);
        }
        
        ApiHelper::jsonResponse([
            'success' => true,
            'api_version' => defined('API_VERSION') ? API_VERSION : '1.0',
            'php_version' => $php_version,
            'database_type' => $is_mariadb ? 'MariaDB' : 'MySQL',
            'database_version' => $version_info,
            'extensions' => $extensions_status,
            'timezone' => date_default_timezone_get(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonError('Version check failed: ' . $e->getMessage());
    }
}

/**
 * Szczegółowy health check z testami funkcjonalności
 */
function detailedHealth($pdo) {
    $start_time = microtime(true);
    $checks = [];
    $overall_status = 'OK';
    
    // Włącz wszystkie sprawdzenia z checkHealth()
    checkHealth($pdo);
    
    // Dodatkowe testy funkcjonalności
    try {
        // Test 1: Symulacja dodania użytkownika
        $test_user_id = 'USR-HEALTHTEST-' . time();
        $stmt = $pdo->prepare("INSERT INTO users (user_id, instance_name) VALUES (?, ?)");
        $stmt->execute([$test_user_id, 'Health Test User']);
        
        // Test 2: Sprawdź czy użytkownik został dodany
        $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$test_user_id]);
        $user_exists = $stmt->rowCount() > 0;
        
        // Test 3: Usuń testowego użytkownika
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$test_user_id]);
        
        if ($user_exists) {
            $checks['crud_operations'] = [
                'status' => 'OK',
                'message' => 'Database CRUD operations working'
            ];
        } else {
            $checks['crud_operations'] = [
                'status' => 'FAIL',
                'message' => 'Database CRUD operations failed'
            ];
            $overall_status = 'CRITICAL';
        }
        
    } catch (Exception $e) {
        $checks['crud_operations'] = [
            'status' => 'FAIL',
            'error' => $e->getMessage()
        ];
        $overall_status = 'CRITICAL';
    }
    
    // Test JSON operations
    try {
        $test_json = ['test' => 'value', 'number' => 123];
        $encoded = json_encode($test_json);
        $decoded = json_decode($encoded, true);
        
        if ($decoded === $test_json) {
            $checks['json_operations'] = [
                'status' => 'OK',
                'message' => 'JSON encoding/decoding working'
            ];
        } else {
            $checks['json_operations'] = [
                'status' => 'FAIL',
                'message' => 'JSON operations failed'
            ];
            if ($overall_status === 'OK') $overall_status = 'WARNING';
        }
    } catch (Exception $e) {
        $checks['json_operations'] = [
            'status' => 'FAIL',
            'error' => $e->getMessage()
        ];
        if ($overall_status === 'OK') $overall_status = 'WARNING';
    }
    
    // Test transactions
    try {
        $pdo->beginTransaction();
        $pdo->exec("SELECT 1"); // Dummy query
        $pdo->rollBack();
        
        $checks['transactions'] = [
            'status' => 'OK',
            'message' => 'Database transactions working'
        ];
    } catch (Exception $e) {
        $checks['transactions'] = [
            'status' => 'FAIL',
            'error' => $e->getMessage()
        ];
        $overall_status = 'CRITICAL';
    }
    
    $response_time = round((microtime(true) - $start_time) * 1000, 2);
    
    $response = [
        'success' => $overall_status !== 'CRITICAL',
        'status' => $overall_status,
        'timestamp' => date('c'),
        'response_time_ms' => $response_time,
        'test_type' => 'detailed',
        'checks' => $checks
    ];
    
    $http_code = ($overall_status === 'CRITICAL') ? 503 : 200;
    ApiHelper::jsonResponse($response, $http_code);
}

/**
 * Sprawdza tylko bazę danych
 */
function checkDatabaseOnly($pdo) {
    $start_time = microtime(true);
    
    try {
        // Test podstawowego połączenia
        $stmt = $pdo->query('SELECT NOW() as current_time, DATABASE() as db_name');
        $db_info = $stmt->fetch();
        
        // Test tabel
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Sprawdź każdą tabelę
        $table_status = [];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $count = $stmt->fetch()['count'];
                $table_status[$table] = [
                    'status' => 'OK',
                    'row_count' => intval($count)
                ];
            } catch (Exception $e) {
                $table_status[$table] = [
                    'status' => 'ERROR',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Test indeksów
        $stmt = $pdo->query("
            SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE()
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ");
        $indexes = $stmt->fetchAll();
        
        $response_time = round((microtime(true) - $start_time) * 1000, 2);
        
        ApiHelper::jsonResponse([
            'success' => true,
            'status' => 'OK',
            'timestamp' => date('c'),
            'response_time_ms' => $response_time,
            'database_info' => [
                'current_time' => $db_info['current_time'],
                'database_name' => $db_info['db_name']
            ],
            'tables' => $table_status,
            'total_tables' => count($tables),
            'indexes_count' => count($indexes)
        ]);
        
    } catch (Exception $e) {
        ApiHelper::jsonResponse([
            'success' => false,
            'status' => 'CRITICAL',
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ], 503);
    }
}

?>