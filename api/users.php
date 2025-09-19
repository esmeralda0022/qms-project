<?php
/**
 * Users Management API
 * Handles comprehensive user CRUD operations, role management, and permissions
 * Implements role-based access control with granular permissions
 */

require_once '../config.php';

// Set JSON header and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Check authentication
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uriParts = explode('/', trim($uri, '/'));

switch ($method) {
    case 'GET':
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    case 'PUT':
        handlePutRequest();
        break;
    case 'DELETE':
        handleDeleteRequest();
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle GET requests for users
 * 
 * Routes:
 * GET /api/users.php - List all users with pagination and filtering
 * GET /api/users.php?id=1 - Get specific user details
 * GET /api/users.php?action=roles - Get available roles
 * GET /api/users.php?action=permissions&user_id=1 - Get user permissions
 */
function handleGetRequest() {
    // Check permissions for user management
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Handle action-based requests
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'roles':
                handleGetRoles();
                return;
            case 'permissions':
                handleGetUserPermissions();
                return;
            default:
                jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
        }
    }
    
    // Get specific user
    if (isset($_GET['id'])) {
        $userId = intval($_GET['id']);
        
        if (!$userId) {
            jsonResponse(['success' => false, 'error' => 'Invalid user ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.email, u.role, u.department_id, u.is_active,
                       u.created_at, u.updated_at,
                       d.name as department_name,
                       created_by.name as created_by_name
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN users created_by ON u.id != created_by.id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            }
            
            // Get user permissions
            $permStmt = $db->prepare("
                SELECT permission, granted
                FROM user_permissions
                WHERE user_id = ?
            ");
            $permStmt->execute([$userId]);
            $permissions = $permStmt->fetchAll();
            
            $user['permissions'] = $permissions;
            
            // Don't return password hash
            unset($user['password_hash']);
            
            jsonResponse([
                'success' => true,
                'data' => $user
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching user: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // List all users with pagination and filtering
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
        $role = isset($_GET['role']) ? sanitize($_GET['role']) : '';
        $department = isset($_GET['department']) ? intval($_GET['department']) : 0;
        $status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        if ($search) {
            $whereConditions[] = "(u.name LIKE ? OR u.email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($role) {
            $whereConditions[] = "u.role = ?";
            $params[] = $role;
        }
        
        if ($department) {
            $whereConditions[] = "u.department_id = ?";
            $params[] = $department;
        }
        
        if ($status !== '') {
            $whereConditions[] = "u.is_active = ?";
            $params[] = $status === 'active' ? 1 : 0;
        }
        
        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        }
        
        // Get total count
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            $whereClause
        ");
        $countStmt->execute($params);
        $totalUsers = $countStmt->fetch()['total'];
        
        // Get users
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, u.department_id, u.is_active,
                   u.created_at, u.updated_at,
                   d.name as department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            $whereClause
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Don't return password hashes
        foreach ($users as &$user) {
            unset($user['password_hash']);
        }
        
        jsonResponse([
            'success' => true,
            'data' => [
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalUsers,
                    'pages' => ceil($totalUsers / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching users: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Handle POST request to create new user
 * POST /api/users.php
 * Body: {name: string, email: string, password: string, role: string, department_id: int}
 */
function handlePostRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['name']) || !isset($input['email']) || !isset($input['password']) || !isset($input['role'])) {
        jsonResponse(['success' => false, 'error' => 'Name, email, password, and role are required'], 400);
    }
    
    $name = sanitize($input['name']);
    $email = sanitize($input['email']);
    $password = $input['password'];
    $role = sanitize($input['role']);
    $departmentId = isset($input['department_id']) ? intval($input['department_id']) : null;
    
    // Validate input
    if (strlen($name) < 2 || strlen($name) > 100) {
        jsonResponse(['success' => false, 'error' => 'Name must be between 2 and 100 characters'], 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
    }
    
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters'], 400);
    }
    
    $validRoles = ['superadmin', 'admin', 'auditor', 'dept_manager', 'technician', 'viewer'];
    if (!in_array($role, $validRoles)) {
        jsonResponse(['success' => false, 'error' => 'Invalid role'], 400);
    }
    
    // Only superadmin can create superadmin and admin users
    if (($role === 'superadmin' || $role === 'admin') && $_SESSION['user_role'] !== 'superadmin') {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions to create admin users'], 403);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Email already exists'], 409);
        }
        
        // Validate department if provided
        if ($departmentId) {
            $stmt = $db->prepare("SELECT id FROM departments WHERE id = ?");
            $stmt->execute([$departmentId]);
            if (!$stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Invalid department ID'], 400);
            }
        }
        
        $db->beginTransaction();
        
        // Create user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            INSERT INTO users (name, email, password_hash, role, department_id, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$name, $email, $passwordHash, $role, $departmentId]);
        $userId = $db->lastInsertId();
        
        // Set default permissions based on role
        $defaultPermissions = getDefaultPermissionsByRole($role);
        if (!empty($defaultPermissions)) {
            $permStmt = $db->prepare("
                INSERT INTO user_permissions (user_id, permission, granted_by)
                VALUES (?, ?, ?)
            ");
            
            foreach ($defaultPermissions as $permission) {
                $permStmt->execute([$userId, $permission, $_SESSION['user_id']]);
            }
        }
        
        $db->commit();
        
        // Log audit trail
        logAuditTrail('create_user', 'user', $userId, [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'department_id' => $departmentId
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['user_id' => $userId]
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Error creating user: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create user'], 500);
    }
}

/**
 * Handle PUT request to update user
 * PUT /api/users.php?id=1
 * Body: {name: string, email: string, role: string, department_id: int, is_active: boolean}
 */
function handlePutRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
    }
    
    $userId = intval($_GET['id']);
    
    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'Invalid user ID'], 400);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid input data'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get existing user
        $stmt = $db->prepare("SELECT id, name, email, role, department_id, is_active FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $existingUser = $stmt->fetch();
        
        if (!$existingUser) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        
        // Prevent users from modifying their own account (except self-updates)
        if ($userId == $_SESSION['user_id'] && isset($input['role'])) {
            jsonResponse(['success' => false, 'error' => 'Cannot change your own role'], 403);
        }
        
        $updateFields = [];
        $params = [];
        
        // Update name
        if (isset($input['name'])) {
            $name = sanitize($input['name']);
            if (strlen($name) < 2 || strlen($name) > 100) {
                jsonResponse(['success' => false, 'error' => 'Name must be between 2 and 100 characters'], 400);
            }
            $updateFields[] = "name = ?";
            $params[] = $name;
        }
        
        // Update email
        if (isset($input['email'])) {
            $email = sanitize($input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
            }
            
            // Check if email already exists for another user
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Email already exists'], 409);
            }
            
            $updateFields[] = "email = ?";
            $params[] = $email;
        }
        
        // Update role
        if (isset($input['role'])) {
            $role = sanitize($input['role']);
            $validRoles = ['superadmin', 'admin', 'auditor', 'dept_manager', 'technician', 'viewer'];
            if (!in_array($role, $validRoles)) {
                jsonResponse(['success' => false, 'error' => 'Invalid role'], 400);
            }
            
            // Only superadmin can assign superadmin role
            if ($role === 'superadmin' && $_SESSION['user_role'] !== 'superadmin') {
                jsonResponse(['success' => false, 'error' => 'Insufficient permissions to assign superadmin role'], 403);
            }
            
            $updateFields[] = "role = ?";
            $params[] = $role;
        }
        
        // Update department
        if (isset($input['department_id'])) {
            $departmentId = $input['department_id'] ? intval($input['department_id']) : null;
            
            if ($departmentId) {
                $stmt = $db->prepare("SELECT id FROM departments WHERE id = ?");
                $stmt->execute([$departmentId]);
                if (!$stmt->fetch()) {
                    jsonResponse(['success' => false, 'error' => 'Invalid department ID'], 400);
                }
            }
            
            $updateFields[] = "department_id = ?";
            $params[] = $departmentId;
        }
        
        // Update active status
        if (isset($input['is_active'])) {
            $isActive = $input['is_active'] ? 1 : 0;
            
            // Prevent deactivating own account
            if ($userId == $_SESSION['user_id'] && !$isActive) {
                jsonResponse(['success' => false, 'error' => 'Cannot deactivate your own account'], 403);
            }
            
            $updateFields[] = "is_active = ?";
            $params[] = $isActive;
        }
        
        if (empty($updateFields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }
        
        $db->beginTransaction();
        
        // Update user
        $params[] = $userId;
        $stmt = $db->prepare("
            UPDATE users 
            SET " . implode(", ", $updateFields) . ", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute($params);
        
        // Update permissions if role changed
        if (isset($input['role']) && $input['role'] !== $existingUser['role']) {
            // Remove existing permissions
            $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Add new default permissions
            $defaultPermissions = getDefaultPermissionsByRole($input['role']);
            if (!empty($defaultPermissions)) {
                $permStmt = $db->prepare("
                    INSERT INTO user_permissions (user_id, permission, granted_by)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($defaultPermissions as $permission) {
                    $permStmt->execute([$userId, $permission, $_SESSION['user_id']]);
                }
            }
        }
        
        $db->commit();
        
        // Log audit trail
        logAuditTrail('update_user', 'user', $userId, $input);
        
        jsonResponse([
            'success' => true,
            'message' => 'User updated successfully'
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Error updating user: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update user'], 500);
    }
}

/**
 * Handle DELETE request to deactivate user
 * DELETE /api/users.php?id=1
 */
function handleDeleteRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
    }
    
    $userId = intval($_GET['id']);
    
    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'Invalid user ID'], 400);
    }
    
    // Prevent deleting own account
    if ($userId == $_SESSION['user_id']) {
        jsonResponse(['success' => false, 'error' => 'Cannot delete your own account'], 403);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        
        // Deactivate user instead of hard delete
        $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$userId]);
        
        // Log audit trail
        logAuditTrail('deactivate_user', 'user', $userId, [
            'name' => $user['name'],
            'email' => $user['email']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'User deactivated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error deactivating user: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to deactivate user'], 500);
    }
}

/**
 * Handle password reset request
 * POST /api/users.php?id=1&action=reset_password
 * Body: {new_password: string}
 */
function handlePasswordReset() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
    }
    
    $userId = intval($_GET['id']);
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['new_password'])) {
        jsonResponse(['success' => false, 'error' => 'New password required'], 400);
    }
    
    $newPassword = $input['new_password'];
    
    if (strlen($newPassword) < 6) {
        jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        
        // Log audit trail
        logAuditTrail('reset_password', 'user', $userId);
        
        jsonResponse([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error resetting password: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to reset password'], 500);
    }
}

/**
 * Get available roles
 */
function handleGetRoles() {
    $roles = [
        'superadmin' => 'Super Administrator',
        'admin' => 'Administrator',
        'auditor' => 'Auditor',
        'dept_manager' => 'Department Manager',
        'technician' => 'Technician',
        'viewer' => 'Viewer'
    ];
    
    // Filter roles based on current user's role
    if ($_SESSION['user_role'] !== 'superadmin') {
        unset($roles['superadmin']);
        if ($_SESSION['user_role'] !== 'admin') {
            unset($roles['admin']);
        }
    }
    
    jsonResponse([
        'success' => true,
        'data' => $roles
    ]);
}

/**
 * Get user permissions
 */
function handleGetUserPermissions() {
    if (!isset($_GET['user_id'])) {
        jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
    }
    
    $userId = intval($_GET['user_id']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT permission, granted
            FROM user_permissions
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $permissions = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => $permissions
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching user permissions: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Get default permissions by role
 * @param string $role User role
 * @return array Array of permissions
 */
function getDefaultPermissionsByRole($role) {
    $permissions = [
        'superadmin' => [
            'manage_users', 'manage_assets', 'manage_departments', 'create_checklists',
            'view_reports', 'manage_ncrs', 'manage_maintenance', 'system_admin'
        ],
        'admin' => [
            'manage_users', 'manage_assets', 'manage_departments', 'create_checklists',
            'view_reports', 'manage_ncrs', 'manage_maintenance'
        ],
        'auditor' => [
            'manage_assets', 'create_checklists', 'view_reports', 'manage_ncrs'
        ],
        'dept_manager' => [
            'manage_assets', 'create_checklists', 'view_reports', 'manage_maintenance'
        ],
        'technician' => [
            'create_checklists', 'view_reports'
        ],
        'viewer' => [
            'view_reports'
        ]
    ];
    
    return $permissions[$role] ?? [];
}
?>