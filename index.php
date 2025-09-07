<?php
/**
 * Price Tracker API - główny punkt wejścia
 * Routing dla wszystkich endpoints
 */

require_once 'config.php';

// Ustawienia CORS
ApiHelper::setCorsHeaders();

// Pobierz ścieżkę z URL
$request_uri = $_SERVER['REQUEST_URI'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path = str_replace(dirname($script_name), '', $request_uri);
$path = trim($path, '/');

// Usuń query string
if (($pos = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $pos);
}

// Parse path
$path_parts = explode('/', $path);

// Routing
try {
    switch ($path_parts[0]) {
        case '':
        case 'api':
            if (isset($path_parts[1])) {
                routeToEndpoint($path_parts[1]);
            } else {
                showApiInfo();
            }
            break;
            
        default:
            ApiHelper::jsonError('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    error_log("API Routing Error: " . $e->getMessage());
    ApiHelper::jsonError('Internal server error', 500);
}

/**
 * Routing do konkretnych endpoints
 */
function routeToEndpoint($endpoint) {
    $endpoint_file = __DIR__ . "/endpoints/{$endpoint}.php";
    
    if (!file_exists($endpoint_file)) {
        ApiHelper::jsonError("Endpoint '{$endpoint}' not found", 404);
    }
    
    // Include endpoint file
    include $endpoint_file;
}

/**
 * Informacje o API
 */
function showApiInfo() {
    $endpoints = [
        'products' => [
            'description' => 'Manage products',
            'methods' => ['GET', 'POST'],
            'actions' => ['list', 'search', 'add', 'bulk_add', 'check_duplicates']
        ],
        'links' => [
            'description' => 'Manage product links',
            'methods' => ['GET', 'POST'],
            'actions' => ['list', 'for_product', 'by_shop', 'add', 'bulk_add']
        ],
        'prices' => [
            'description' => 'Manage prices',
            'methods' => ['GET', 'POST'],
            'actions' => ['latest', 'for_product', 'history', 'stats', 'add', 'bulk_add']
        ],
        'shop_configs' => [
            'description' => 'Manage shop configurations',
            'methods' => ['GET', 'POST'],
            'actions' => ['list', 'get', 'selectors', 'update', 'bulk_update']
        ],
        'substitutes' => [
            'description' => 'Manage substitute groups',
            'methods' => ['GET', 'POST'],
            'actions' => ['list', 'for_product', 'add', 'bulk_add']
        ],
        'sync' => [
            'description' => 'Synchronization endpoints',
            'methods' => ['GET', 'POST'],
            'actions' => ['status', 'full_sync']
        ],
        'health' => [
            'description' => 'API health check',
            'methods' => ['GET'],
            'actions' => ['status', 'version']
        ]
    ];
    
    ApiHelper::jsonResponse([
        'success' => true,
        'api_name' => 'Price Tracker API',
        'version' => API_VERSION,
        'timestamp' => date('c'),
        'endpoints' => $endpoints,
        'authentication' => 'user_id required in request body or X-User-ID header',
        'rate_limit' => MAX_REQUESTS_PER_HOUR . ' requests per hour per user',
        'documentation' => 'https://your-domain.com/api/docs'
    ]);
}

?>