<?php
/**
 * QuailtyMed Configuration File
 * Database connection and application settings for XAMPP environment
 * 
 * Instructions:
 * 1. Update DB_HOST, DB_USER, DB_PASS as needed for your XAMPP setup
 * 2. Ensure 'quailtymed' database exists and is imported from database.sql
 * 3. Set timezone to match your location (default: Asia/Kolkata)
 */

// Set default timezone for the application
date_default_timezone_set('Asia/Kolkata');

// Database Configuration for XAMPP (MySQL/MariaDB)
define('DB_HOST', 'localhost');        // XAMPP MySQL host
define('DB_USER', 'root');            // XAMPP default MySQL user
define('DB_PASS', '');                // XAMPP default MySQL password (empty)
define('DB_NAME', 'quailtymed');      // Database name from database.sql

// Application Configuration
define('APP_NAME', 'QuailtyMed');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/qms-project/');

// Security Settings
define('SESSION_TIMEOUT', 3600);      // Session timeout in seconds (1 hour)
define('MAX_FILE_SIZE', 5242880);     // Maximum file upload size (5MB)
define('UPLOAD_PATH', __DIR__ . '/uploads/');

// Allowed file types for uploads (MIME types)
define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
    'application/pdf', 'text/plain', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
]);

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Database Connection Class
 * Provides secure database connection using PDO with prepared statements
 */
class Database {
    private static $instance = null;
    private $connection;
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get database instance (Singleton pattern)
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO connection
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }
}

/**
 * Utility Functions
 */

/**
 * Sanitize input data to prevent XSS attacks
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token for form protection
 * @return string CSRF token
 */
function generateCSRFToken() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool True if valid, false otherwise
 */
function verifyCSRFToken($token) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

/**
 * Check if user has required role
 * @param array $allowedRoles Array of allowed roles
 * @return bool True if user has required role, false otherwise
 */
function hasRole($allowedRoles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    return in_array($_SESSION['user_role'], $allowedRoles);
}

/**
 * Check if user has specific permission
 * @param string $permission Permission to check
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Super admin has all permissions
    if ($_SESSION['user_role'] === 'superadmin') {
        return true;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT granted 
            FROM user_permissions 
            WHERE user_id = ? AND permission = ? AND granted = 1
        ");
        $stmt->execute([$_SESSION['user_id'], $permission]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        error_log("Permission check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user's department access
 * @return array Array of accessible department IDs
 */
function getUserDepartmentAccess() {
    if (!isLoggedIn()) {
        return [];
    }
    
    // Super admin and admin have access to all departments
    if (in_array($_SESSION['user_role'], ['superadmin', 'admin'])) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id FROM departments ORDER BY name");
            $stmt->execute();
            return array_column($stmt->fetchAll(), 'id');
        } catch (Exception $e) {
            error_log("Department access check failed: " . $e->getMessage());
            return [];
        }
    }
    
    // Other users have access only to their assigned department
    return isset($_SESSION['department_id']) ? [$_SESSION['department_id']] : [];
}

/**
 * Check if user can access specific department
 * @param int $departmentId Department ID to check
 * @return bool True if user can access department, false otherwise
 */
function canAccessDepartment($departmentId) {
    $accessibleDepts = getUserDepartmentAccess();
    return in_array($departmentId, $accessibleDepts);
}

/**
 * Get role hierarchy for permission inheritance
 * @return array Role hierarchy with numeric levels
 */
function getRoleHierarchy() {
    return [
        'viewer' => 1,
        'technician' => 2,
        'dept_manager' => 3,
        'auditor' => 4,
        'admin' => 5,
        'superadmin' => 6
    ];
}

/**
 * Check if user role has higher or equal level than required role
 * @param string $requiredRole Minimum required role
 * @return bool True if user role is sufficient, false otherwise
 */
function hasRoleLevel($requiredRole) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $hierarchy = getRoleHierarchy();
    $userLevel = $hierarchy[$_SESSION['user_role']] ?? 0;
    $requiredLevel = $hierarchy[$requiredRole] ?? 99;
    
    return $userLevel >= $requiredLevel;
}

/**
 * Log audit trail entry
 * @param string $action Action performed
 * @param string $entityType Entity type affected
 * @param int $entityId Entity ID affected
 * @param array $metadata Additional metadata
 */
function logAuditTrail($action, $entityType = null, $entityId = null, $metadata = []) {
    if (!isLoggedIn()) {
        return;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, meta_json, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $entityType,
            $entityId,
            json_encode($metadata),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Send JSON response with proper headers
 * @param array $data Response data
 * @param int $statusCode HTTP status code
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Generate unique NCR number
 * @return string NCR number format: NCR-YYYY-NNNN
 */
function generateNCRNumber() {
    $db = Database::getInstance()->getConnection();
    $year = date('Y');
    
    // Get the next sequence number for this year
    $stmt = $db->prepare("
        SELECT COUNT(*) + 1 as next_num 
        FROM ncrs 
        WHERE ncr_number LIKE ?
    ");
    $stmt->execute(["NCR-$year-%"]);
    $result = $stmt->fetch();
    
    return sprintf("NCR-%s-%04d", $year, $result['next_num']);
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

// Check session timeout
if (isLoggedIn() && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            jsonResponse(['success' => false, 'error' => 'Session expired'], 401);
        } else {
            header('Location: login.php');
            exit;
        }
    }
}

if (isLoggedIn()) {
    $_SESSION['last_activity'] = time();
}
?>
