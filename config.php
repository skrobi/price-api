<?php
/**
 * Konfiguracja API Price Tracker
 */

// Konfiguracja bazy danych
define('DB_HOST', 'localhost');
define('DB_NAME', 'price_tracker');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// Ustawienia API
define('API_VERSION', '1.0');
define('API_TIMEOUT', 30);
define('MAX_REQUESTS_PER_HOUR', 1000);

// Ustawienia bezpieczeństwa
define('RATE_LIMIT_ENABLED', true);
define('LOG_REQUESTS', true);

// Dozwolone origins (CORS)
$allowed_origins = [
    'http://localhost:5000',
    'http://127.0.0.1:5000',
    // Dodaj inne origins jeśli potrzeba
];

/**
 * Klasa do połączenia z bazą danych
 */
class Database {
    private $pdo;
    
    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

/**
 * Klasa pomocnicza API
 */
class ApiHelper {
    
    /**
     * Ustawia nagłówki CORS
     */
    public static function setCorsHeaders() {
        global $allowed_origins;
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: $origin");
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-ID');
        header('Access-Control-Max-Age: 3600');
        
        // Obsługa preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
    
    /**
     * Zwraca odpowiedź JSON
     */
    public static function jsonResponse($data, $status_code = 200) {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
    
    /**
     * Zwraca błąd JSON
     */
    public static function jsonError($message, $status_code = 400) {
        self::jsonResponse([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ], $status_code);
    }
    
    /**
     * Waliduje user_id
     */
    public static function validateUserId($user_id) {
        if (empty($user_id) || !preg_match('/^USR-[A-Z0-9]{12}-[0-9]{8}$/', $user_id)) {
            return false;
        }
        return true;
    }
    
    /**
     * Pobiera user_id z request
     */
    public static function getUserId() {
        $user_id = $_POST['user_id'] ?? $_GET['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;
        
        if (!$user_id) {
            $input = json_decode(file_get_contents('php://input'), true);
            $user_id = $input['user_id'] ?? null;
        }
        
        if (!self::validateUserId($user_id)) {
            self::jsonError('Invalid or missing user_id', 401);
        }
        
        return $user_id;
    }
    
    /**
     * Rate limiting
     */
    public static function checkRateLimit($user_id) {
        if (!RATE_LIMIT_ENABLED) return true;
        
        // Proste rate limiting w plikach (można przenieść do Redis)
        $rate_file = "/tmp/rate_limit_$user_id";
        $current_time = time();
        $window_start = $current_time - 3600; // 1 godzina
        
        $requests = [];
        if (file_exists($rate_file)) {
            $requests = json_decode(file_get_contents($rate_file), true) ?? [];
        }
        
        // Usuń stare requesty
        $requests = array_filter($requests, function($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });
        
        // Sprawdź limit
        if (count($requests) >= MAX_REQUESTS_PER_HOUR) {
            self::jsonError('Rate limit exceeded. Max ' . MAX_REQUESTS_PER_HOUR . ' requests per hour.', 429);
        }
        
        // Dodaj obecny request
        $requests[] = $current_time;
        file_put_contents($rate_file, json_encode($requests));
        
        return true;
    }
    
    /**
     * Loguje request
     */
    public static function logRequest($endpoint, $user_id, $method, $data = null) {
        if (!LOG_REQUESTS) return;
        
        $log_entry = [
            'timestamp' => date('c'),
            'endpoint' => $endpoint,
            'method' => $method,
            'user_id' => $user_id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'data_size' => $data ? strlen(json_encode($data)) : 0
        ];
        
        $log_file = 'logs/api_' . date('Y-m-d') . '.log';
        if (!is_dir('logs')) mkdir('logs', 0755, true);
        
        file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND);
    }
    
    /**
     * Rejestruje użytkownika jeśli nie istnieje
     */
    public static function ensureUserExists($user_id, $instance_name = null) {
        try {
            $db = new Database();
            $pdo = $db->getConnection();
            
            // Sprawdź czy użytkownik istnieje
            $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            if ($stmt->rowCount() === 0) {
                // Zarejestruj nowego użytkownika
                $stmt = $pdo->prepare("INSERT INTO users (user_id, instance_name) VALUES (?, ?)");
                $stmt->execute([$user_id, $instance_name ?: "PriceTracker-" . substr($user_id, -8)]);
            } else {
                // Aktualizuj last_active
                $stmt = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
                $stmt->execute([$user_id]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error ensuring user exists: " . $e->getMessage());
            return false;
        }
    }
}

// Ustawienia błędów
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

// Ustawienia czasowe
date_default_timezone_set('Europe/Warsaw');

?>