<?php
/**
 * Checklists API
 * Handles checklist creation, updates, and NABH/JCI compliance tracking
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
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle GET requests for checklists
 * 
 * Routes:
 * GET /api/checklists.php?asset_id=1&type=Daily - Get checklists for asset and document type
 * GET /api/checklists.php?id=1 - Get specific checklist with items
 * GET /api/checklists.php - List all checklists for current user
 */
function handleGetRequest() {
    $db = Database::getInstance()->getConnection();
    
    // Get specific checklist with items and standards
    if (isset($_GET['id'])) {
        $checklistId = intval($_GET['id']);
        
        if (!$checklistId) {
            jsonResponse(['success' => false, 'error' => 'Invalid checklist ID'], 400);
        }
        
        try {
            // Get checklist header
            $stmt = $db->prepare("
                SELECT c.id, c.asset_id, c.document_type_id, c.checklist_name, c.status, 
                       c.due_date, c.completed_at, c.created_at,
                       a.name as asset_name, a.asset_tag, a.location,
                       dt.name as document_type_name,
                       u1.name as created_by_name, u2.name as assigned_to_name
                FROM checklists c
                JOIN assets a ON c.asset_id = a.id
                JOIN document_types dt ON c.document_type_id = dt.id
                JOIN users u1 ON c.created_by = u1.id
                LEFT JOIN users u2 ON c.assigned_to = u2.id
                WHERE c.id = ?
            ");
            $stmt->execute([$checklistId]);
            $checklist = $stmt->fetch();
            
            if (!$checklist) {
                jsonResponse(['success' => false, 'error' => 'Checklist not found'], 404);
            }
            
            // Get checklist items with standards
            $stmt = $db->prepare("
                SELECT ci.id, ci.question, ci.result, ci.remarks, ci.attached_file, 
                       ci.checked_at, ci.order_index,
                       s.id as standard_id, s.source as standard_source, 
                       s.clause_id, s.title as standard_title, s.description as standard_desc,
                       u.name as checked_by_name
                FROM checklist_items ci
                LEFT JOIN standards s ON ci.standard_id = s.id
                LEFT JOIN users u ON ci.checked_by = u.id
                WHERE ci.checklist_id = ?
                ORDER BY ci.order_index, ci.id
            ");
            $stmt->execute([$checklistId]);
            $items = $stmt->fetchAll();
            
            $checklist['items'] = $items;
            
            jsonResponse([
                'success' => true,
                'data' => $checklist
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching checklist: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // Get checklists for specific asset and document type
    if (isset($_GET['asset_id']) && isset($_GET['type'])) {
        $assetId = intval($_GET['asset_id']);
        $documentType = sanitize($_GET['type']);
        
        if (!$assetId) {
            jsonResponse(['success' => false, 'error' => 'Invalid asset ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                SELECT c.id, c.checklist_name, c.status, c.due_date, c.created_at,
                       c.completed_at, dt.name as document_type_name,
                       u1.name as created_by_name, u2.name as assigned_to_name
                FROM checklists c
                JOIN document_types dt ON c.document_type_id = dt.id
                JOIN users u1 ON c.created_by = u1.id
                LEFT JOIN users u2 ON c.assigned_to = u2.id
                WHERE c.asset_id = ? AND dt.name LIKE ?
                ORDER BY c.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$assetId, "%$documentType%"]);
            $checklists = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $checklists
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching asset checklists: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
        }
        
        return;
    }
    
    // Default: List recent checklists for current user
    try {
        $stmt = $db->prepare("
            SELECT c.id, c.checklist_name, c.status, c.due_date, c.created_at,
                   a.name as asset_name, a.asset_tag,
                   dt.name as document_type_name,
                   d.name as department_name
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            JOIN document_types dt ON c.document_type_id = dt.id
            WHERE c.assigned_to = ? OR c.created_by = ?
            ORDER BY c.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $checklists = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => $checklists
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching user checklists: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

/**
 * Handle POST request to create new checklist
 * POST /api/checklists.php
 * Body: {asset_id: int, document_type_id: int, checklist_name: string, items: array}
 */
function handlePostRequest() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['asset_id']) || !isset($input['document_type_id'])) {
        jsonResponse(['success' => false, 'error' => 'Asset ID and document type ID required'], 400);
    }
    
    $assetId = intval($input['asset_id']);
    $documentTypeId = intval($input['document_type_id']);
    $checklistName = isset($input['checklist_name']) ? sanitize($input['checklist_name']) : '';
    $items = isset($input['items']) ? $input['items'] : [];
    
    if (!$assetId || !$documentTypeId) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset or document type ID'], 400);
    }
    
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        // Create checklist
        $stmt = $db->prepare("
            INSERT INTO checklists (asset_id, document_type_id, checklist_name, created_by, assigned_to, status, due_date)
            VALUES (?, ?, ?, ?, ?, 'draft', DATE_ADD(CURDATE(), INTERVAL 1 DAY))
        ");
        $stmt->execute([$assetId, $documentTypeId, $checklistName, $_SESSION['user_id'], $_SESSION['user_id']]);
        $checklistId = $db->lastInsertId();
        
        // Create default checklist items if none provided
        if (empty($items)) {
            $items = getDefaultChecklistItems($documentTypeId);
        }
        
        // Insert checklist items
        $itemStmt = $db->prepare("
            INSERT INTO checklist_items (checklist_id, standard_id, question, order_index)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($items as $index => $item) {
            $standardId = isset($item['standard_id']) ? intval($item['standard_id']) : null;
            $question = sanitize($item['question']);
            $itemStmt->execute([$checklistId, $standardId, $question, $index + 1]);
        }
        
        $db->commit();
        
        // Log audit trail
        logAuditTrail('create_checklist', 'checklist', $checklistId, [
            'asset_id' => $assetId,
            'document_type_id' => $documentTypeId
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => ['checklist_id' => $checklistId]
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error creating checklist: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create checklist'], 500);
    }
}

/**
 * Handle PUT request to update checklist item
 * PUT /api/checklists.php?id=1&item_id=1
 * Body: {result: string, remarks: string}
 */
function handlePutRequest() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id']) || !isset($_GET['item_id'])) {
        jsonResponse(['success' => false, 'error' => 'Checklist ID and item ID required'], 400);
    }
    
    $checklistId = intval($_GET['id']);
    $itemId = intval($_GET['item_id']);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['result'])) {
        jsonResponse(['success' => false, 'error' => 'Result required'], 400);
    }
    
    $result = sanitize($input['result']);
    $remarks = isset($input['remarks']) ? sanitize($input['remarks']) : '';
    
    if (!in_array($result, ['pass', 'fail', 'na'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid result value'], 400);
    }
    
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        // Update checklist item
        $stmt = $db->prepare("
            UPDATE checklist_items 
            SET result = ?, remarks = ?, checked_by = ?, checked_at = NOW()
            WHERE id = ? AND checklist_id = ?
        ");
        $stmt->execute([$result, $remarks, $_SESSION['user_id'], $itemId, $checklistId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Checklist item not found'], 404);
        }
        
        // If result is 'fail', create NCR
        if ($result === 'fail') {
            createNCRForFailedItem($itemId, $checklistId, $remarks);
        }
        
        // Update checklist status to in_progress
        $stmt = $db->prepare("
            UPDATE checklists 
            SET status = 'in_progress'
            WHERE id = ? AND status = 'draft'
        ");
        $stmt->execute([$checklistId]);
        
        $db->commit();
        
        // Log audit trail
        logAuditTrail('update_checklist_item', 'checklist_item', $itemId, [
            'checklist_id' => $checklistId,
            'result' => $result,
            'ncr_created' => ($result === 'fail')
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Checklist item updated successfully'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error updating checklist item: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update checklist item'], 500);
    }
}

/**
 * Get default checklist items based on document type
 * @param int $documentTypeId Document type ID
 * @return array Default items with NABH/JCI standards
 */
function getDefaultChecklistItems($documentTypeId) {
    // Sample default items mapped to NABH/JCI standards
    $defaultItems = [
        1 => [ // Daily Checklist
            ['question' => 'Equipment is clean and free from damage', 'standard_id' => 4],
            ['question' => 'All safety devices are functioning properly', 'standard_id' => 2],
            ['question' => 'Operating parameters are within specified ranges', 'standard_id' => 4],
            ['question' => 'Log books are properly maintained', 'standard_id' => 1]
        ],
        2 => [ // Preventive Maintenance
            ['question' => 'Equipment maintenance schedule is followed', 'standard_id' => 4],
            ['question' => 'All components inspected and cleaned', 'standard_id' => 4],
            ['question' => 'Safety systems tested and verified', 'standard_id' => 2],
            ['question' => 'Maintenance records updated', 'standard_id' => 1]
        ],
        3 => [ // Calibration
            ['question' => 'Calibration standards are traceable', 'standard_id' => 4],
            ['question' => 'Equipment accuracy is within tolerance', 'standard_id' => 4],
            ['question' => 'Calibration certificate issued', 'standard_id' => 4],
            ['question' => 'Next calibration date scheduled', 'standard_id' => 1]
        ]
    ];
    
    return $defaultItems[$documentTypeId] ?? $defaultItems[1];
}

/**
 * Create NCR for failed checklist item
 * @param int $itemId Checklist item ID
 * @param int $checklistId Checklist ID
 * @param string $remarks Failure remarks
 */
function createNCRForFailedItem($itemId, $checklistId, $remarks) {
    $db = Database::getInstance()->getConnection();
    
    // Get checklist and asset details
    $stmt = $db->prepare("
        SELECT c.asset_id, at.department_id, a.name as asset_name, ci.question
        FROM checklists c
        JOIN assets a ON c.asset_id = a.id
        JOIN asset_types at ON a.asset_type_id = at.id
        JOIN checklist_items ci ON ci.id = ?
        WHERE c.id = ?
    ");
    $stmt->execute([$itemId, $checklistId]);
    $details = $stmt->fetch();
    
    if ($details) {
        $ncrNumber = generateNCRNumber();
        $description = "Non-compliance identified during checklist: {$details['question']}. Details: $remarks";
        
        $stmt = $db->prepare("
            INSERT INTO ncrs (ncr_number, checklist_item_id, asset_id, department_id, description, 
                             raised_by, status, severity, due_date)
            VALUES (?, ?, ?, ?, ?, ?, 'open', 'medium', DATE_ADD(CURDATE(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            $ncrNumber, $itemId, $details['asset_id'], 
            $details['department_id'], $description, $_SESSION['user_id']
        ]);
        
        logAuditTrail('create_ncr', 'ncr', $db->lastInsertId(), [
            'ncr_number' => $ncrNumber,
            'checklist_item_id' => $itemId
        ]);
    }
}
?>
