<?php
/**
 * Price Tracker API - prosty routing
 */

// Podstawowe headers
header('Content-Type: application/json; charset=utf-8');

// Pobierz ścieżkę z URL
$request_uri = $_SERVER['REQUEST_URI'];

// Debug - pokaż co mamy
// echo "Request URI: " . $request_uri . "\n";

// Wyciągnij część po /price-api/
if (strpos($request_uri, '/price-api/') !== false) {
    $path = substr($request_uri, strpos($request_uri, '/price-api/') + strlen('/price-api/'));
} else {
    $path = '';
}

// Usuń query string
if (strpos($path, '?') !== false) {
    $path = substr($path, 0, strpos($path, '?'));
}

// Usuń slashe
$path = trim($path, '/');

// Jeśli brak ścieżki - pokaż info
if (empty($path)) {
    echo json_encode([
        'success' => true,
        'message' => 'Price Tracker API',
        'version' => '1.0',
        'endpoints' => [
            'health' => '/health',
            'products' => '/products', 
            'test' => '/test'
        ],
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Test endpoint
if ($path === 'test') {
    echo json_encode([
        'success' => true,
        'message' => 'Test endpoint działa!',
        'path' => $path,
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Health endpoint
if ($path === 'health') {
    echo json_encode([
        'success' => true,
        'status' => 'OK',
        'message' => 'API is running',
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Endpoint nie znaleziony
echo json_encode([
    'success' => false,
    'error' => 'Endpoint not found: ' . $path,
    'available' => ['health', 'test'],
    'time' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);

?>