<?php
/**
 * Departments API
 * Handles department listing, asset types, and document types
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
        handleGetRequest($uriParts);
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle GET requests for departments and related data
 * 
 * Routes:
 * GET /api/departments.php - List all departments
 * GET /api/departments.php?id=1&asset_types=1 - Get asset types for department
 * GET /api/departments.php?asset_type_id=1&document_types=1 - Get document types for asset type
 */
function handleGetRequest($uriParts) {
    $db = Database::getInstance()->getConnection();
    
    // Get asset types for a specific department
    if (isset($_GET['id']) && isset($_GET['asset_types'])) {
        $deptId = intval($_GET['id']);
        
        if (!$deptId) {
            jsonResponse(['success' => false, 'error' => 'Invalid department ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                SELECT id, name, description
                FROM asset_types 
                WHERE department_id = ?
                ORDER BY name
            ");
            $stmt->execute([$deptId]);
            $assetTypes = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $assetTypes
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching asset types: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // Get document types for a specific asset type
    if (isset($_GET['asset_type_id']) && isset($_GET['document_types'])) {
        try {
            // Get all document types (they're shared across asset types)
            $stmt = $db->prepare("
                SELECT id, name, description, requires_signature, validity_days
                FROM document_types
                ORDER BY name
            ");
            $stmt->execute();
            $documentTypes = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $documentTypes
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching document types: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // Default: List all departments
    try {
        $stmt = $db->prepare("
            SELECT d.id, d.name, d.description, 
                   u.name as head_name,
                   COUNT(at.id) as asset_types_count
            FROM departments d
            LEFT JOIN users u ON d.head_user_id = u.id
            LEFT JOIN asset_types at ON d.id = at.department_id
            GROUP BY d.id, d.name, d.description, u.name
            ORDER BY d.name
        ");
        $stmt->execute();
        $departments = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => $departments
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching departments: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}
?>
