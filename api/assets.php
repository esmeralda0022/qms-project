<?php
/**
 * Assets Management API
 * Handles comprehensive asset CRUD operations, maintenance scheduling, and tracking
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
 * Handle GET requests for assets
 * 
 * Routes:
 * GET /api/assets.php - List all assets with filtering
 * GET /api/assets.php?id=1 - Get specific asset details
 * GET /api/assets.php?asset_type_id=1 - Get assets by type
 * GET /api/assets.php?department_id=1 - Get assets by department
 * GET /api/assets.php?search=transformer - Search assets
 */
function handleGetRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician', 'viewer'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Get specific asset
    if (isset($_GET['id'])) {
        $assetId = intval($_GET['id']);
        
        if (!$assetId) {
            jsonResponse(['success' => false, 'error' => 'Invalid asset ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                SELECT a.*, at.name as asset_type_name, d.name as department_name,
                       ms.next_due as next_maintenance, ms.frequency as maintenance_frequency,
                       COUNT(c.id) as total_checklists,
                       COUNT(CASE WHEN c.status = 'completed' THEN 1 END) as completed_checklists
                FROM assets a
                JOIN asset_types at ON a.asset_type_id = at.id
                JOIN departments d ON at.department_id = d.id
                LEFT JOIN maintenance_schedules ms ON a.id = ms.asset_id AND ms.is_active = 1
                LEFT JOIN checklists c ON a.id = c.asset_id
                WHERE a.id = ?
                GROUP BY a.id
            ");
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch();
            
            if (!$asset) {
                jsonResponse(['success' => false, 'error' => 'Asset not found'], 404);
            }
            
            // Get recent maintenance history
            $historyStmt = $db->prepare("
                SELECT mr.*, u.name as performed_by_name, dt.name as maintenance_type_name
                FROM maintenance_records mr
                LEFT JOIN users u ON mr.performed_by = u.id
                LEFT JOIN document_types dt ON mr.maintenance_type = dt.name
                WHERE mr.asset_id = ?
                ORDER BY mr.created_at DESC
                LIMIT 10
            ");
            $historyStmt->execute([$assetId]);
            $asset['maintenance_history'] = $historyStmt->fetchAll();
            
            // Get active NCRs
            $ncrStmt = $db->prepare("
                SELECT id, ncr_number, description, status, severity, created_at
                FROM ncrs
                WHERE asset_id = ? AND status != 'closed'
                ORDER BY created_at DESC
            ");
            $ncrStmt->execute([$assetId]);
            $asset['active_ncrs'] = $ncrStmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $asset
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching asset: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // List assets with filtering
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
        $assetTypeId = isset($_GET['asset_type_id']) ? intval($_GET['asset_type_id']) : 0;
        $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
        $status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
        $dueMaintenance = isset($_GET['due_maintenance']) && $_GET['due_maintenance'] === 'true';
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        if ($search) {
            $whereConditions[] = "(a.name LIKE ? OR a.asset_tag LIKE ? OR a.model LIKE ? OR a.serial_no LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($assetTypeId) {
            $whereConditions[] = "a.asset_type_id = ?";
            $params[] = $assetTypeId;
        }
        
        if ($departmentId) {
            $whereConditions[] = "at.department_id = ?";
            $params[] = $departmentId;
        }
        
        if ($status) {
            $whereConditions[] = "a.status = ?";
            $params[] = $status;
        }
        
        if ($dueMaintenance) {
            $whereConditions[] = "ms.next_due <= CURDATE()";
        }
        
        // Apply department restrictions for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            $whereConditions[] = "at.department_id = ?";
            $params[] = $_SESSION['department_id'];
        }
        
        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        }
        
        // Get total count
        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT a.id) as total
            FROM assets a
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            LEFT JOIN maintenance_schedules ms ON a.id = ms.asset_id AND ms.is_active = 1
            $whereClause
        ");
        $countStmt->execute($params);
        $totalAssets = $countStmt->fetch()['total'];
        
        // Get assets
        $stmt = $db->prepare("
            SELECT a.*, at.name as asset_type_name, d.name as department_name,
                   ms.next_due as next_maintenance, ms.frequency as maintenance_frequency,
                   DATEDIFF(ms.next_due, CURDATE()) as days_to_maintenance,
                   COUNT(nc.id) as open_ncrs
            FROM assets a
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            LEFT JOIN maintenance_schedules ms ON a.id = ms.asset_id AND ms.is_active = 1
            LEFT JOIN ncrs nc ON a.id = nc.asset_id AND nc.status != 'closed'
            $whereClause
            GROUP BY a.id
            ORDER BY a.name ASC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $assets = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => [
                'assets' => $assets,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalAssets,
                    'pages' => ceil($totalAssets / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching assets: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Handle POST request to create new asset
 * POST /api/assets.php
 * Body: {asset_type_id, name, asset_tag, model, serial_no, location, vendor, installation_date, warranty_end, next_calibration_date}
 */
function handlePostRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['asset_type_id']) || !isset($input['name'])) {
        jsonResponse(['success' => false, 'error' => 'Asset type ID and name are required'], 400);
    }
    
    $assetTypeId = intval($input['asset_type_id']);
    $name = sanitize($input['name']);
    $assetTag = isset($input['asset_tag']) ? sanitize($input['asset_tag']) : null;
    $model = isset($input['model']) ? sanitize($input['model']) : null;
    $serialNo = isset($input['serial_no']) ? sanitize($input['serial_no']) : null;
    $location = isset($input['location']) ? sanitize($input['location']) : null;
    $vendor = isset($input['vendor']) ? sanitize($input['vendor']) : null;
    $installationDate = isset($input['installation_date']) ? $input['installation_date'] : null;
    $warrantyEnd = isset($input['warranty_end']) ? $input['warranty_end'] : null;
    $nextCalibrationDate = isset($input['next_calibration_date']) ? $input['next_calibration_date'] : null;
    $status = isset($input['status']) ? sanitize($input['status']) : 'active';
    
    // Validate input
    if (!$assetTypeId) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset type ID'], 400);
    }
    
    if (strlen($name) < 2 || strlen($name) > 150) {
        jsonResponse(['success' => false, 'error' => 'Name must be between 2 and 150 characters'], 400);
    }
    
    $validStatuses = ['active', 'inactive', 'maintenance', 'decommissioned'];
    if (!in_array($status, $validStatuses)) {
        jsonResponse(['success' => false, 'error' => 'Invalid status'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Validate asset type exists
        $stmt = $db->prepare("SELECT id, department_id FROM asset_types WHERE id = ?");
        $stmt->execute([$assetTypeId]);
        $assetType = $stmt->fetch();
        
        if (!$assetType) {
            jsonResponse(['success' => false, 'error' => 'Invalid asset type ID'], 400);
        }
        
        // Check department access for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            if ($assetType['department_id'] != $_SESSION['department_id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
            }
        }
        
        // Check if asset tag already exists
        if ($assetTag) {
            $stmt = $db->prepare("SELECT id FROM assets WHERE asset_tag = ?");
            $stmt->execute([$assetTag]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Asset tag already exists'], 409);
            }
        }
        
        $db->beginTransaction();
        
        // Create asset
        $stmt = $db->prepare("
            INSERT INTO assets (asset_type_id, name, asset_tag, model, serial_no, location, 
                               vendor, installation_date, warranty_end, next_calibration_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $assetTypeId, $name, $assetTag, $model, $serialNo, $location,
            $vendor, $installationDate, $warrantyEnd, $nextCalibrationDate, $status
        ]);
        $assetId = $db->lastInsertId();
        
        // Create default maintenance schedules if specified
        if (isset($input['maintenance_schedules']) && is_array($input['maintenance_schedules'])) {
            $scheduleStmt = $db->prepare("
                INSERT INTO maintenance_schedules (asset_id, document_type_id, frequency, frequency_value, next_due, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            foreach ($input['maintenance_schedules'] as $schedule) {
                $documentTypeId = intval($schedule['document_type_id']);
                $frequency = sanitize($schedule['frequency']);
                $frequencyValue = intval($schedule['frequency_value']) ?: 1;
                $nextDue = $schedule['next_due'];
                
                $scheduleStmt->execute([$assetId, $documentTypeId, $frequency, $frequencyValue, $nextDue]);
            }
        }
        
        $db->commit();
        
        // Log audit trail
        logAuditTrail('create_asset', 'asset', $assetId, [
            'name' => $name,
            'asset_tag' => $assetTag,
            'asset_type_id' => $assetTypeId
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['asset_id' => $assetId]
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Error creating asset: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create asset'], 500);
    }
}

/**
 * Handle PUT request to update asset
 * PUT /api/assets.php?id=1
 * Body: {name, asset_tag, model, serial_no, location, vendor, installation_date, warranty_end, next_calibration_date, status}
 */
function handlePutRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'Asset ID required'], 400);
    }
    
    $assetId = intval($_GET['id']);
    
    if (!$assetId) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset ID'], 400);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid input data'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get existing asset and check access
        $stmt = $db->prepare("
            SELECT a.id, a.name, at.department_id
            FROM assets a
            JOIN asset_types at ON a.asset_type_id = at.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assetId]);
        $existingAsset = $stmt->fetch();
        
        if (!$existingAsset) {
            jsonResponse(['success' => false, 'error' => 'Asset not found'], 404);
        }
        
        // Check department access for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            if ($existingAsset['department_id'] != $_SESSION['department_id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
            }
        }
        
        $updateFields = [];
        $params = [];
        
        // Update name
        if (isset($input['name'])) {
            $name = sanitize($input['name']);
            if (strlen($name) < 2 || strlen($name) > 150) {
                jsonResponse(['success' => false, 'error' => 'Name must be between 2 and 150 characters'], 400);
            }
            $updateFields[] = "name = ?";
            $params[] = $name;
        }
        
        // Update asset tag
        if (isset($input['asset_tag'])) {
            $assetTag = $input['asset_tag'] ? sanitize($input['asset_tag']) : null;
            
            if ($assetTag) {
                // Check if asset tag already exists for another asset
                $stmt = $db->prepare("SELECT id FROM assets WHERE asset_tag = ? AND id != ?");
                $stmt->execute([$assetTag, $assetId]);
                if ($stmt->fetch()) {
                    jsonResponse(['success' => false, 'error' => 'Asset tag already exists'], 409);
                }
            }
            
            $updateFields[] = "asset_tag = ?";
            $params[] = $assetTag;
        }
        
        // Update other fields
        $fields = ['model', 'serial_no', 'location', 'vendor'];
        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = sanitize($input[$field]);
            }
        }
        
        // Update dates
        $dateFields = ['installation_date', 'warranty_end', 'next_calibration_date'];
        foreach ($dateFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $input[$field] ?: null;
            }
        }
        
        // Update status
        if (isset($input['status'])) {
            $status = sanitize($input['status']);
            $validStatuses = ['active', 'inactive', 'maintenance', 'decommissioned'];
            if (!in_array($status, $validStatuses)) {
                jsonResponse(['success' => false, 'error' => 'Invalid status'], 400);
            }
            $updateFields[] = "status = ?";
            $params[] = $status;
        }
        
        if (empty($updateFields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }
        
        // Update asset
        $params[] = $assetId;
        $stmt = $db->prepare("
            UPDATE assets 
            SET " . implode(", ", $updateFields) . ", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute($params);
        
        // Log audit trail
        logAuditTrail('update_asset', 'asset', $assetId, $input);
        
        jsonResponse([
            'success' => true,
            'message' => 'Asset updated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error updating asset: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update asset'], 500);
    }
}

/**
 * Handle DELETE request to deactivate asset
 * DELETE /api/assets.php?id=1
 */
function handleDeleteRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'Asset ID required'], 400);
    }
    
    $assetId = intval($_GET['id']);
    
    if (!$assetId) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset ID'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if asset exists and get details
        $stmt = $db->prepare("
            SELECT a.id, a.name, a.asset_tag, at.department_id
            FROM assets a
            JOIN asset_types at ON a.asset_type_id = at.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();
        
        if (!$asset) {
            jsonResponse(['success' => false, 'error' => 'Asset not found'], 404);
        }
        
        // Check department access for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            if ($asset['department_id'] != $_SESSION['department_id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
            }
        }
        
        // Check if asset has active checklists
        $stmt = $db->prepare("SELECT COUNT(*) as active_checklists FROM checklists WHERE asset_id = ? AND status IN ('draft', 'in_progress')");
        $stmt->execute([$assetId]);
        $activeChecklists = $stmt->fetch()['active_checklists'];
        
        if ($activeChecklists > 0) {
            jsonResponse(['success' => false, 'error' => 'Cannot delete asset with active checklists'], 409);
        }
        
        // Deactivate asset instead of hard delete
        $stmt = $db->prepare("UPDATE assets SET status = 'decommissioned', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$assetId]);
        
        // Deactivate maintenance schedules
        $stmt = $db->prepare("UPDATE maintenance_schedules SET is_active = 0 WHERE asset_id = ?");
        $stmt->execute([$assetId]);
        
        // Log audit trail
        logAuditTrail('decommission_asset', 'asset', $assetId, [
            'name' => $asset['name'],
            'asset_tag' => $asset['asset_tag']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Asset decommissioned successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error decommissioning asset: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to decommission asset'], 500);
    }
}
?>