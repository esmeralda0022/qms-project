<?php
/**
 * Authentication API
 * Handles user login, logout, and session management
 */

require_once '../config.php';

// Set JSON header
header('Content-Type: application/json');

// Handle CORS for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        handleLogin();
        break;
    case 'GET':
        handleGetUser();
        break;
    case 'DELETE':
        handleLogout();
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle user login
 * POST /api/auth.php
 * Body: {email: string, password: string}
 */
function handleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['email']) || !isset($input['password'])) {
        jsonResponse(['success' => false, 'error' => 'Email and password required'], 400);
    }
    
    $email = sanitize($input['email']);
    $password = $input['password'];
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get user by email with department info
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.password_hash, u.role, u.department_id, u.is_active,
                   d.name as department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Invalid email or password'], 401);
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid email or password'], 401);
        }
        
        // Create session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['department_name'] = $user['department_name'];
        $_SESSION['last_activity'] = time();
        
        // Log successful login
        logAuditTrail('login', 'user', $user['id']);
        
        // Return user info (without sensitive data)
        jsonResponse([
            'success' => true,
            'data' => [
                'user_id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'department_id' => $user['department_id'],
                'department_name' => $user['department_name']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Get current user info
 * GET /api/auth.php
 */
function handleGetUser() {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    jsonResponse([
        'success' => true,
        'data' => [
            'user_id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role'],
            'department_id' => $_SESSION['department_id'],
            'department_name' => $_SESSION['department_name']
        ]
    ]);
}

/**
 * Handle user logout
 * DELETE /api/auth.php
 */
function handleLogout() {
    if (isLoggedIn()) {
        logAuditTrail('logout', 'user', $_SESSION['user_id']);
        session_destroy();
    }
    
    jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}
?>
