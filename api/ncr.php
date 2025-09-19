<?php
/**
 * NCR Management API
 * Handles Non-Conformance Reports, CAPA tracking, and workflow management
 */

require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

function handleGetRequest() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician', 'viewer'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $db = Database::getInstance()->getConnection();
    
    if (isset($_GET['id'])) {
        $ncrId = intval($_GET['id']);
        
        try {
            $stmt = $db->prepare("
                SELECT n.*, a.name as asset_name, a.asset_tag, d.name as department_name,
                       u1.name as raised_by_name, u2.name as assigned_to_name,
                       ci.question as related_question
                FROM ncrs n
                LEFT JOIN assets a ON n.asset_id = a.id
                LEFT JOIN departments d ON n.department_id = d.id
                LEFT JOIN users u1 ON n.raised_by = u1.id
                LEFT JOIN users u2 ON n.assigned_to = u2.id
                LEFT JOIN checklist_items ci ON n.checklist_item_id = ci.id
                WHERE n.id = ?
            ");
            $stmt->execute([$ncrId]);
            $ncr = $stmt->fetch();
            
            if (!$ncr) {
                jsonResponse(['success' => false, 'error' => 'NCR not found'], 404);
            }
            
            // Get NCR actions
            $actionsStmt = $db->prepare("
                SELECT na.*, u1.name as assigned_to_name, u2.name as created_by_name
                FROM ncr_actions na
                LEFT JOIN users u1 ON na.assigned_to = u1.id
                LEFT JOIN users u2 ON na.created_by = u2.id
                WHERE na.ncr_id = ?
                ORDER BY na.created_at ASC
            ");
            $actionsStmt->execute([$ncrId]);
            $ncr['actions'] = $actionsStmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $ncr]);
            
        } catch (Exception $e) {
            error_log("Error fetching NCR: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // List NCRs with filtering
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        $status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
        $severity = isset($_GET['severity']) ? sanitize($_GET['severity']) : '';
        $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
        $assignedTo = isset($_GET['assigned_to']) ? intval($_GET['assigned_to']) : 0;
        
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "n.status = ?";
            $params[] = $status;
        }
        
        if ($severity) {
            $whereConditions[] = "n.severity = ?";
            $params[] = $severity;
        }
        
        if ($departmentId) {
            $whereConditions[] = "n.department_id = ?";
            $params[] = $departmentId;
        }
        
        if ($assignedTo) {
            $whereConditions[] = "n.assigned_to = ?";
            $params[] = $assignedTo;
        }
        
        // Apply department restrictions for non-admin users
        if (!hasRole(['superadmin', 'admin']) && isset($_SESSION['department_id'])) {
            $whereConditions[] = "n.department_id = ?";
            $params[] = $_SESSION['department_id'];
        }
        
        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        }
        
        // Get total count
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM ncrs n
            $whereClause
        ");
        $countStmt->execute($params);
        $totalNCRs = $countStmt->fetch()['total'];
        
        // Get NCRs
        $stmt = $db->prepare("
            SELECT n.*, a.name as asset_name, a.asset_tag, d.name as department_name,
                   u1.name as raised_by_name, u2.name as assigned_to_name,
                   DATEDIFF(CURDATE(), n.created_at) as days_old,
                   COUNT(na.id) as action_count
            FROM ncrs n
            LEFT JOIN assets a ON n.asset_id = a.id
            LEFT JOIN departments d ON n.department_id = d.id
            LEFT JOIN users u1 ON n.raised_by = u1.id
            LEFT JOIN users u2 ON n.assigned_to = u2.id
            LEFT JOIN ncr_actions na ON n.id = na.ncr_id
            $whereClause
            GROUP BY n.id
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $ncrs = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => [
                'ncrs' => $ncrs,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $totalNCRs,
                    'pages' => ceil($totalNCRs / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching NCRs: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

function handlePostRequest() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    // Handle creating NCR action if action_type is provided
    if (isset($_GET['ncr_id']) && isset($_GET['action'])) {
        handleCreateAction();
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['description']) || !isset($input['department_id'])) {
        jsonResponse(['success' => false, 'error' => 'Description and department ID are required'], 400);
    }
    
    $description = sanitize($input['description']);
    $departmentId = intval($input['department_id']);
    $assetId = isset($input['asset_id']) ? intval($input['asset_id']) : null;
    $checklistItemId = isset($input['checklist_item_id']) ? intval($input['checklist_item_id']) : null;
    $severity = isset($input['severity']) ? sanitize($input['severity']) : 'medium';
    $assignedTo = isset($input['assigned_to']) ? intval($input['assigned_to']) : null;
    
    if (!$departmentId) {
        jsonResponse(['success' => false, 'error' => 'Invalid department ID'], 400);
    }
    
    $validSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $validSeverities)) {
        jsonResponse(['success' => false, 'error' => 'Invalid severity'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generate NCR number
        $ncrNumber = generateNCRNumber();
        
        // Set due date (7 days from now for medium, varies by severity)
        $dueDays = ['low' => 14, 'medium' => 7, 'high' => 3, 'critical' => 1];
        $dueDate = date('Y-m-d', strtotime("+{$dueDays[$severity]} days"));
        
        $db->beginTransaction();
        
        // Create NCR
        $stmt = $db->prepare("
            INSERT INTO ncrs (ncr_number, checklist_item_id, asset_id, department_id, description,
                             raised_by, assigned_to, status, severity, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)
        ");
        $stmt->execute([$ncrNumber, $checklistItemId, $assetId, $departmentId, $description,
                       $_SESSION['user_id'], $assignedTo, $severity, $dueDate]);
        $ncrId = $db->lastInsertId();
        
        // Create initial corrective action
        if ($assignedTo) {
            $stmt = $db->prepare("
                INSERT INTO ncr_actions (ncr_id, action_type, description, assigned_to, due_date, status, created_by)
                VALUES (?, 'corrective', 'Investigate root cause and implement corrective measures', ?, ?, 'pending', ?)
            ");
            $stmt->execute([$ncrId, $assignedTo, $dueDate, $_SESSION['user_id']]);
        }
        
        $db->commit();
        
        logAuditTrail('create_ncr', 'ncr', $ncrId, [
            'ncr_number' => $ncrNumber,
            'severity' => $severity,
            'department_id' => $departmentId
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['ncr_id' => $ncrId, 'ncr_number' => $ncrNumber]
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Error creating NCR: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create NCR'], 500);
    }
}

function handleCreateAction() {
    $ncrId = intval($_GET['ncr_id']);
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action_type']) || !isset($input['description'])) {
        jsonResponse(['success' => false, 'error' => 'Action type and description are required'], 400);
    }
    
    $actionType = sanitize($input['action_type']);
    $description = sanitize($input['description']);
    $assignedTo = isset($input['assigned_to']) ? intval($input['assigned_to']) : null;
    $dueDate = isset($input['due_date']) ? $input['due_date'] : null;
    
    $validActionTypes = ['immediate', 'corrective', 'preventive', 'verification'];
    if (!in_array($actionType, $validActionTypes)) {
        jsonResponse(['success' => false, 'error' => 'Invalid action type'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO ncr_actions (ncr_id, action_type, description, assigned_to, due_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$ncrId, $actionType, $description, $assignedTo, $dueDate, $_SESSION['user_id']]);
        $actionId = $db->lastInsertId();
        
        logAuditTrail('create_ncr_action', 'ncr_action', $actionId, [
            'ncr_id' => $ncrId,
            'action_type' => $actionType
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['action_id' => $actionId]
        ]);
        
    } catch (Exception $e) {
        error_log("Error creating NCR action: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create action'], 500);
    }
}

function handlePutRequest() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'NCR ID required'], 400);
    }
    
    $ncrId = intval($_GET['id']);
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid input data'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $updateFields = [];
        $params = [];
        
        if (isset($input['status'])) {
            $status = sanitize($input['status']);
            $validStatuses = ['open', 'in_progress', 'completed', 'verified', 'closed'];
            if (!in_array($status, $validStatuses)) {
                jsonResponse(['success' => false, 'error' => 'Invalid status'], 400);
            }
            $updateFields[] = "status = ?";
            $params[] = $status;
            
            if ($status === 'completed') {
                $updateFields[] = "completed_date = CURDATE()";
            }
        }
        
        if (isset($input['assigned_to'])) {
            $updateFields[] = "assigned_to = ?";
            $params[] = $input['assigned_to'] ? intval($input['assigned_to']) : null;
        }
        
        if (isset($input['root_cause'])) {
            $updateFields[] = "root_cause = ?";
            $params[] = sanitize($input['root_cause']);
        }
        
        if (isset($input['corrective_action'])) {
            $updateFields[] = "corrective_action = ?";
            $params[] = sanitize($input['corrective_action']);
        }
        
        if (isset($input['preventive_action'])) {
            $updateFields[] = "preventive_action = ?";
            $params[] = sanitize($input['preventive_action']);
        }
        
        if (isset($input['evidence'])) {
            $updateFields[] = "evidence = ?";
            $params[] = sanitize($input['evidence']);
        }
        
        if (empty($updateFields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }
        
        $params[] = $ncrId;
        $stmt = $db->prepare("
            UPDATE ncrs 
            SET " . implode(", ", $updateFields) . ", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute($params);
        
        logAuditTrail('update_ncr', 'ncr', $ncrId, $input);
        
        jsonResponse([
            'success' => true,
            'message' => 'NCR updated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error updating NCR: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update NCR'], 500);
    }
}

function handleDeleteRequest() {
    if (!hasRole(['superadmin', 'admin'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'NCR ID required'], 400);
    }
    
    $ncrId = intval($_GET['id']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if NCR exists
        $stmt = $db->prepare("SELECT id, ncr_number FROM ncrs WHERE id = ?");
        $stmt->execute([$ncrId]);
        $ncr = $stmt->fetch();
        
        if (!$ncr) {
            jsonResponse(['success' => false, 'error' => 'NCR not found'], 404);
        }
        
        // Soft delete by updating status to cancelled
        $stmt = $db->prepare("UPDATE ncrs SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$ncrId]);
        
        logAuditTrail('close_ncr', 'ncr', $ncrId, [
            'ncr_number' => $ncr['ncr_number']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'NCR closed successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error closing NCR: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to close NCR'], 500);
    }
}
?>