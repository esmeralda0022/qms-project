<?php
/**
 * Maintenance Management API
 * Handles preventive maintenance scheduling, execution tracking, and automation
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
 * Handle GET requests for maintenance
 * 
 * Routes:
 * GET /api/maintenance.php - List maintenance schedules
 * GET /api/maintenance.php?id=1 - Get specific schedule
 * GET /api/maintenance.php?overdue=true - Get overdue maintenance
 * GET /api/maintenance.php?dashboard=true - Get dashboard metrics
 */
function handleGetRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician', 'viewer'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Handle dashboard metrics request
    if (isset($_GET['dashboard']) && $_GET['dashboard'] === 'true') {
        handleDashboardMetrics($db);
        return;
    }
    
    // Get specific maintenance schedule
    if (isset($_GET['id'])) {
        $scheduleId = intval($_GET['id']);
        
        if (!$scheduleId) {
            jsonResponse(['success' => false, 'error' => 'Invalid schedule ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                SELECT ms.*, a.name as asset_name, a.asset_tag, a.location,
                       at.name as asset_type_name, d.name as department_name,
                       dt.name as document_type_name,
                       COUNT(mr.id) as total_records,
                       MAX(mr.created_at) as last_performed
                FROM maintenance_schedules ms
                JOIN assets a ON ms.asset_id = a.id
                JOIN asset_types at ON a.asset_type_id = at.id
                JOIN departments d ON at.department_id = d.id
                JOIN document_types dt ON ms.document_type_id = dt.id
                LEFT JOIN maintenance_records mr ON ms.id = mr.maintenance_schedule_id
                WHERE ms.id = ?
                GROUP BY ms.id
            ");
            $stmt->execute([$scheduleId]);
            $schedule = $stmt->fetch();
            
            if (!$schedule) {
                jsonResponse(['success' => false, 'error' => 'Schedule not found'], 404);
            }
            
            // Get recent maintenance records
            $recordsStmt = $db->prepare("
                SELECT mr.*, u.name as performed_by_name
                FROM maintenance_records mr
                LEFT JOIN users u ON mr.performed_by = u.id
                WHERE mr.maintenance_schedule_id = ?
                ORDER BY mr.created_at DESC
                LIMIT 10
            ");
            $recordsStmt->execute([$scheduleId]);
            $schedule['recent_records'] = $recordsStmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $schedule
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching maintenance schedule: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // List maintenance schedules with filtering
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        $assetId = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : 0;
        $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
        $frequency = isset($_GET['frequency']) ? sanitize($_GET['frequency']) : '';
        $overdue = isset($_GET['overdue']) && $_GET['overdue'] === 'true';
        $dueSoon = isset($_GET['due_soon']) && $_GET['due_soon'] === 'true';
        $isActive = isset($_GET['active']) ? ($_GET['active'] === 'true' ? 1 : 0) : null;
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        if ($assetId) {
            $whereConditions[] = \"ms.asset_id = ?\";
            $params[] = $assetId;
        }
        
        if ($departmentId) {
            $whereConditions[] = \"d.id = ?\";
            $params[] = $departmentId;
        }
        
        if ($frequency) {
            $whereConditions[] = \"ms.frequency = ?\";
            $params[] = $frequency;
        }
        
        if ($overdue) {
            $whereConditions[] = \"ms.next_due < CURDATE()\";
        }
        
        if ($dueSoon) {
            $whereConditions[] = \"ms.next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)\";
        }
        
        if ($isActive !== null) {
            $whereConditions[] = \"ms.is_active = ?\";
            $params[] = $isActive;
        }
        
        // Apply department restrictions for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            $whereConditions[] = \"d.id = ?\";
            $params[] = $_SESSION['department_id'];
        }
        
        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        }
        
        // Get total count
        $countStmt = $db->prepare(\"
            SELECT COUNT(*) as total
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            $whereClause
        \");
        $countStmt->execute($params);
        $totalSchedules = $countStmt->fetch()['total'];
        
        // Get schedules
        $stmt = $db->prepare(\"
            SELECT ms.*, a.name as asset_name, a.asset_tag, a.location,
                   at.name as asset_type_name, d.name as department_name,
                   dt.name as document_type_name,
                   DATEDIFF(ms.next_due, CURDATE()) as days_until_due,
                   COUNT(mr.id) as total_records,
                   MAX(mr.created_at) as last_performed
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            JOIN document_types dt ON ms.document_type_id = dt.id
            LEFT JOIN maintenance_records mr ON ms.id = mr.maintenance_schedule_id
            $whereClause
            GROUP BY ms.id
            ORDER BY ms.next_due ASC
            LIMIT ? OFFSET ?
        \");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $schedules = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => [
                'schedules' => $schedules,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalSchedules,
                    'pages' => ceil($totalSchedules / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        error_log(\"Error fetching maintenance schedules: \" . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Handle dashboard metrics request
 * @param PDO $db Database connection
 */
function handleDashboardMetrics($db) {
    try {
        // Apply department restrictions for non-admin users
        $deptFilter = '';
        $deptParams = [];
        
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            $deptFilter = 'AND d.id = ?';
            $deptParams[] = $_SESSION['department_id'];
        }
        
        // Get overdue maintenance count
        $overdueStmt = $db->prepare(\"
            SELECT COUNT(*) as count
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            WHERE ms.next_due < CURDATE() AND ms.is_active = 1 $deptFilter
        \");
        $overdueStmt->execute($deptParams);
        $overdueCount = $overdueStmt->fetch()['count'];
        
        // Get due soon count (next 7 days)
        $dueSoonStmt = $db->prepare(\"
            SELECT COUNT(*) as count
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            WHERE ms.next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
            AND ms.is_active = 1 $deptFilter
        \");
        $dueSoonStmt->execute($deptParams);
        $dueSoonCount = $dueSoonStmt->fetch()['count'];
        
        // Get pending checklists count
        $pendingStmt = $db->prepare(\"
            SELECT COUNT(*) as count
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            WHERE c.status IN ('draft', 'in_progress') $deptFilter
        \");
        $pendingStmt->execute($deptParams);
        $pendingChecklists = $pendingStmt->fetch()['count'];
        
        // Get open NCRs count
        $ncrStmt = $db->prepare(\"
            SELECT COUNT(*) as count
            FROM ncrs n
            JOIN departments d ON n.department_id = d.id
            WHERE n.status IN ('open', 'in_progress') $deptFilter
        \");
        $ncrStmt->execute($deptParams);
        $openNCRs = $ncrStmt->fetch()['count'];
        
        // Calculate compliance rate (completed checklists vs total in last 30 days)
        $complianceStmt = $db->prepare(\"
            SELECT 
                COUNT(*) as total_checklists,
                COUNT(CASE WHEN c.status = 'completed' THEN 1 END) as completed_checklists
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            WHERE c.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) $deptFilter
        \");
        $complianceStmt->execute($deptParams);
        $complianceData = $complianceStmt->fetch();
        
        $complianceRate = 0;
        if ($complianceData['total_checklists'] > 0) {
            $complianceRate = round(($complianceData['completed_checklists'] / $complianceData['total_checklists']) * 100, 1);
        }
        
        jsonResponse([
            'success' => true,
            'data' => [
                'overdue_maintenance' => $overdueCount,
                'due_soon_maintenance' => $dueSoonCount,
                'pending_checklists' => $pendingChecklists,
                'open_ncrs' => $openNCRs,
                'compliance_rate' => $complianceRate
            ]
        ]);
        
    } catch (Exception $e) {
        error_log(\"Error fetching dashboard metrics: \" . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Handle POST request to create maintenance schedule
 * POST /api/maintenance.php
 * Body: {asset_id, document_type_id, frequency, frequency_value, next_due}
 */
function handlePostRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['asset_id']) || !isset($input['document_type_id']) || !isset($input['frequency'])) {
        jsonResponse(['success' => false, 'error' => 'Asset ID, document type ID, and frequency are required'], 400);
    }
    
    $assetId = intval($input['asset_id']);
    $documentTypeId = intval($input['document_type_id']);
    $frequency = sanitize($input['frequency']);
    $frequencyValue = isset($input['frequency_value']) ? intval($input['frequency_value']) : 1;
    $nextDue = isset($input['next_due']) ? $input['next_due'] : null;
    
    // Validate input
    if (!$assetId || !$documentTypeId) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset or document type ID'], 400);
    }
    
    $validFrequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
    if (!in_array($frequency, $validFrequencies)) {
        jsonResponse(['success' => false, 'error' => 'Invalid frequency'], 400);
    }
    
    if ($frequencyValue < 1) {
        jsonResponse(['success' => false, 'error' => 'Frequency value must be positive'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Validate asset and document type exist
        $stmt = $db->prepare(\"
            SELECT a.id, at.department_id
            FROM assets a
            JOIN asset_types at ON a.asset_type_id = at.id
            WHERE a.id = ?
        \");
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
        
        $stmt = $db->prepare(\"SELECT id FROM document_types WHERE id = ?\");
        $stmt->execute([$documentTypeId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Document type not found'], 404);
        }
        
        // Check if schedule already exists for this asset and document type
        $stmt = $db->prepare(\"
            SELECT id FROM maintenance_schedules 
            WHERE asset_id = ? AND document_type_id = ? AND is_active = 1
        \");
        $stmt->execute([$assetId, $documentTypeId]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Maintenance schedule already exists for this asset and document type'], 409);
        }
        
        // Calculate next due date if not provided
        if (!$nextDue) {
            $nextDue = calculateNextDueDate($frequency, $frequencyValue);
        }
        
        // Create maintenance schedule
        $stmt = $db->prepare(\"
            INSERT INTO maintenance_schedules (asset_id, document_type_id, frequency, frequency_value, next_due, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        \");
        $stmt->execute([$assetId, $documentTypeId, $frequency, $frequencyValue, $nextDue]);
        $scheduleId = $db->lastInsertId();
        
        // Log audit trail
        logAuditTrail('create_maintenance_schedule', 'maintenance_schedule', $scheduleId, [
            'asset_id' => $assetId,
            'document_type_id' => $documentTypeId,
            'frequency' => $frequency
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['schedule_id' => $scheduleId]
        ]);
        
    } catch (Exception $e) {
        error_log(\"Error creating maintenance schedule: \" . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create maintenance schedule'], 500);
    }
}

/**
 * Calculate next due date based on frequency
 * @param string $frequency Frequency type
 * @param int $frequencyValue Frequency multiplier
 * @return string Next due date
 */
function calculateNextDueDate($frequency, $frequencyValue) {
    $date = new DateTime();
    
    switch ($frequency) {
        case 'daily':
            $date->add(new DateInterval(\"P{$frequencyValue}D\"));
            break;
        case 'weekly':
            $days = $frequencyValue * 7;
            $date->add(new DateInterval(\"P{$days}D\"));
            break;
        case 'monthly':
            $date->add(new DateInterval(\"P{$frequencyValue}M\"));
            break;
        case 'quarterly':
            $months = $frequencyValue * 3;
            $date->add(new DateInterval(\"P{$months}M\"));
            break;
        case 'yearly':
            $date->add(new DateInterval(\"P{$frequencyValue}Y\"));
            break;
    }
    
    return $date->format('Y-m-d');
}

/**
 * Handle PUT request to update maintenance schedule
 * PUT /api/maintenance.php?id=1
 */
function handlePutRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'Schedule ID required'], 400);
    }
    
    $scheduleId = intval($_GET['id']);
    
    if (!$scheduleId) {
        jsonResponse(['success' => false, 'error' => 'Invalid schedule ID'], 400);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid input data'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get existing schedule and check access
        $stmt = $db->prepare(\"
            SELECT ms.id, a.id as asset_id, at.department_id
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            WHERE ms.id = ?
        \");
        $stmt->execute([$scheduleId]);
        $existingSchedule = $stmt->fetch();
        
        if (!$existingSchedule) {
            jsonResponse(['success' => false, 'error' => 'Schedule not found'], 404);
        }
        
        // Check department access for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            if ($existingSchedule['department_id'] != $_SESSION['department_id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
            }
        }
        
        $updateFields = [];
        $params = [];
        
        // Update frequency
        if (isset($input['frequency'])) {
            $frequency = sanitize($input['frequency']);
            $validFrequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
            if (!in_array($frequency, $validFrequencies)) {
                jsonResponse(['success' => false, 'error' => 'Invalid frequency'], 400);
            }
            $updateFields[] = \"frequency = ?\";
            $params[] = $frequency;
        }
        
        // Update frequency value
        if (isset($input['frequency_value'])) {
            $frequencyValue = intval($input['frequency_value']);
            if ($frequencyValue < 1) {
                jsonResponse(['success' => false, 'error' => 'Frequency value must be positive'], 400);
            }
            $updateFields[] = \"frequency_value = ?\";
            $params[] = $frequencyValue;
        }
        
        // Update next due date
        if (isset($input['next_due'])) {
            $updateFields[] = \"next_due = ?\";
            $params[] = $input['next_due'];
        }
        
        // Update last done date
        if (isset($input['last_done'])) {
            $updateFields[] = \"last_done = ?\";
            $params[] = $input['last_done'];
        }
        
        // Update active status
        if (isset($input['is_active'])) {
            $updateFields[] = \"is_active = ?\";
            $params[] = $input['is_active'] ? 1 : 0;
        }
        
        if (empty($updateFields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }
        
        // Update schedule
        $params[] = $scheduleId;
        $stmt = $db->prepare(\"
            UPDATE maintenance_schedules 
            SET \" . implode(\", \", $updateFields) . \"
            WHERE id = ?
        \");
        $stmt->execute($params);
        
        // Log audit trail
        logAuditTrail('update_maintenance_schedule', 'maintenance_schedule', $scheduleId, $input);
        
        jsonResponse([
            'success' => true,
            'message' => 'Maintenance schedule updated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log(\"Error updating maintenance schedule: \" . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update maintenance schedule'], 500);
    }
}

/**
 * Handle DELETE request to deactivate maintenance schedule
 * DELETE /api/maintenance.php?id=1
 */
function handleDeleteRequest() {
    // Check permissions
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'Schedule ID required'], 400);
    }
    
    $scheduleId = intval($_GET['id']);
    
    if (!$scheduleId) {
        jsonResponse(['success' => false, 'error' => 'Invalid schedule ID'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if schedule exists and get details
        $stmt = $db->prepare(\"
            SELECT ms.id, a.name as asset_name, dt.name as document_type_name, at.department_id
            FROM maintenance_schedules ms
            JOIN assets a ON ms.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN document_types dt ON ms.document_type_id = dt.id
            WHERE ms.id = ?
        \");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();
        
        if (!$schedule) {
            jsonResponse(['success' => false, 'error' => 'Schedule not found'], 404);
        }
        
        // Check department access for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            if ($schedule['department_id'] != $_SESSION['department_id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
            }
        }
        
        // Deactivate schedule instead of hard delete
        $stmt = $db->prepare(\"UPDATE maintenance_schedules SET is_active = 0 WHERE id = ?\");
        $stmt->execute([$scheduleId]);
        
        // Log audit trail
        logAuditTrail('deactivate_maintenance_schedule', 'maintenance_schedule', $scheduleId, [
            'asset_name' => $schedule['asset_name'],
            'document_type_name' => $schedule['document_type_name']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Maintenance schedule deactivated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log(\"Error deactivating maintenance schedule: \" . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to deactivate maintenance schedule'], 500);
    }
}
?>"
        , "original_text": ""},
        {"new_text": "", "original_text": ""}]