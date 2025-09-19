<?php
/**
 * Reports API - Advanced Reporting Features
 * Handles dashboard metrics, compliance reports, audit trails, and analytics
 * 
 * Features:
 * - Comprehensive dashboard metrics with time-based filtering
 * - Department performance analytics
 * - Compliance rate calculations and trends
 * - Asset utilization and maintenance tracking
 * - NCR analysis and CAPA effectiveness
 * - Audit trails and activity logs
 * - Export capabilities for reports
 */

require_once '../config.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure user is authenticated and has permission
session_start();
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

// Check if user has reporting permissions
if (!hasReportingPermission($userRole)) {
    sendJsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action);
            break;
        case 'POST':
            handlePostRequest($action);
            break;
        default:
            sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError('Reports API Error', $e->getMessage(), [
        'user_id' => $userId,
        'method' => $method,
        'action' => $action
    ]);
    
    sendJsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests for reports
 */
function handleGetRequest($action) {
    switch ($action) {
        case 'dashboard_analytics':
            getDashboardAnalytics();
            break;
        case 'compliance_report':
            getComplianceReport();
            break;
        case 'department_performance':
            getDepartmentPerformance();
            break;
        case 'asset_utilization':
            getAssetUtilization();
            break;
        case 'ncr_analysis':
            getNCRAnalysis();
            break;
        case 'audit_trail':
            getAuditTrail();
            break;
        case 'maintenance_trends':
            getMaintenanceTrends();
            break;
        default:
            sendJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
}

/**
 * Handle POST requests for report generation and exports
 */
function handlePostRequest($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'generate_custom_report':
            generateCustomReport($input);
            break;
        case 'export_report':
            exportReport($input);
            break;
        default:
            sendJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
}

/**
 * Get comprehensive dashboard analytics
 */
function getDashboardAnalytics() {
    global $pdo, $userId, $userRole;
    
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-01'), // Start of current month
        'date_to' => $_GET['date_to'] ?? date('Y-m-t'), // End of current month
        'department_id' => $_GET['department_id'] ?? null
    ];
    
    $departmentFilter = '';
    $params = [$filters['date_from'], $filters['date_to']];
    
    if ($filters['department_id']) {
        $departmentFilter = ' AND d.id = ?';
        $params[] = $filters['department_id'];
    }
    
    // Restrict to user's department if not admin
    if (!in_array($userRole, ['superadmin', 'admin'])) {
        $departmentFilter .= ' AND d.id = ?';
        $params[] = $_SESSION['department_id'];
    }
    
    try {
        // Overall metrics
        $metrics = [
            'total_checklists' => 0,
            'completed_checklists' => 0,
            'pending_checklists' => 0,
            'overdue_checklists' => 0,
            'compliance_rate' => 0,
            'total_assets' => 0,
            'active_assets' => 0,
            'maintenance_due' => 0,
            'overdue_maintenance' => 0,
            'total_ncrs' => 0,
            'open_ncrs' => 0,
            'closed_ncrs' => 0,
            'critical_ncrs' => 0
        ];
        
        // Checklist metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN c.status = 'pending' AND c.due_date < NOW() THEN 1 ELSE 0 END) as overdue
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            JOIN departments d ON a.department_id = d.id
            WHERE c.created_at BETWEEN ? AND ? $departmentFilter
        ");
        $stmt->execute($params);
        $checklistData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['total_checklists'] = (int)$checklistData['total'];
        $metrics['completed_checklists'] = (int)$checklistData['completed'];
        $metrics['pending_checklists'] = (int)$checklistData['pending'];
        $metrics['overdue_checklists'] = (int)$checklistData['overdue'];
        $metrics['compliance_rate'] = $metrics['total_checklists'] > 0 
            ? round(($metrics['completed_checklists'] / $metrics['total_checklists']) * 100, 1) 
            : 0;
        
        // Asset metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN a.next_maintenance_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as maintenance_due,
                SUM(CASE WHEN a.next_maintenance_date < NOW() THEN 1 ELSE 0 END) as overdue_maintenance
            FROM assets a
            JOIN departments d ON a.department_id = d.id
            WHERE 1=1 $departmentFilter
        ");
        $assetParams = [];
        if ($filters['department_id']) {
            $assetParams[] = $filters['department_id'];
        }
        if (!in_array($userRole, ['superadmin', 'admin'])) {
            $assetParams[] = $_SESSION['department_id'];
        }
        $stmt->execute($assetParams);
        $assetData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['total_assets'] = (int)$assetData['total'];
        $metrics['active_assets'] = (int)$assetData['active'];
        $metrics['maintenance_due'] = (int)$assetData['maintenance_due'];
        $metrics['overdue_maintenance'] = (int)$assetData['overdue_maintenance'];
        
        // NCR metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN n.status IN ('open', 'in_progress') THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN n.status = 'closed' THEN 1 ELSE 0 END) as closed,
                SUM(CASE WHEN n.severity = 'critical' AND n.status != 'closed' THEN 1 ELSE 0 END) as critical
            FROM ncrs n
            JOIN departments d ON n.department_id = d.id
            WHERE n.created_at BETWEEN ? AND ? $departmentFilter
        ");
        $stmt->execute($params);
        $ncrData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metrics['total_ncrs'] = (int)$ncrData['total'];
        $metrics['open_ncrs'] = (int)$ncrData['open'];
        $metrics['closed_ncrs'] = (int)$ncrData['closed'];
        $metrics['critical_ncrs'] = (int)$ncrData['critical'];
        
        // Trend data (last 6 months)
        $trends = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-$i months"));
            $monthEnd = date('Y-m-t', strtotime("-$i months"));
            $monthName = date('M Y', strtotime("-$i months"));
            
            // Compliance rate for this month
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM checklists c
                JOIN assets a ON c.asset_id = a.id
                JOIN departments d ON a.department_id = d.id
                WHERE c.created_at BETWEEN ? AND ? $departmentFilter
            ");
            $monthParams = [$monthStart, $monthEnd];
            if ($filters['department_id']) {
                $monthParams[] = $filters['department_id'];
            }
            if (!in_array($userRole, ['superadmin', 'admin'])) {
                $monthParams[] = $_SESSION['department_id'];
            }
            $stmt->execute($monthParams);
            $monthData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $monthCompliance = $monthData['total'] > 0 
                ? round(($monthData['completed'] / $monthData['total']) * 100, 1) 
                : 0;
            
            $trends[] = [
                'month' => $monthName,
                'compliance_rate' => $monthCompliance,
                'total_checklists' => (int)$monthData['total'],
                'completed_checklists' => (int)$monthData['completed']
            ];
        }
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'metrics' => $metrics,
                'trends' => $trends,
                'period' => [
                    'from' => $filters['date_from'],
                    'to' => $filters['date_to']
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Get compliance report with detailed breakdown
 */
function getComplianceReport() {
    global $pdo, $userId, $userRole;
    
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
        'date_to' => $_GET['date_to'] ?? date('Y-m-t'),
        'department_id' => $_GET['department_id'] ?? null
    ];
    
    $departmentFilter = '';
    $params = [$filters['date_from'], $filters['date_to']];
    
    if ($filters['department_id']) {
        $departmentFilter = ' AND d.id = ?';
        $params[] = $filters['department_id'];
    }
    
    if (!in_array($userRole, ['superadmin', 'admin'])) {
        $departmentFilter .= ' AND d.id = ?';
        $params[] = $_SESSION['department_id'];
    }
    
    try {
        // Department-wise compliance
        $stmt = $pdo->prepare("
            SELECT 
                d.id,
                d.name as department_name,
                COUNT(c.id) as total_checklists,
                SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) as completed_checklists,
                SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending_checklists,
                SUM(CASE WHEN c.status = 'pending' AND c.due_date < NOW() THEN 1 ELSE 0 END) as overdue_checklists,
                ROUND(
                    (SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) / 
                     NULLIF(COUNT(c.id), 0)) * 100, 1
                ) as compliance_rate
            FROM departments d
            LEFT JOIN assets a ON d.id = a.department_id
            LEFT JOIN checklists c ON a.id = c.asset_id 
                AND c.created_at BETWEEN ? AND ?
            WHERE 1=1 $departmentFilter
            GROUP BY d.id, d.name
            ORDER BY compliance_rate DESC
        ");
        $stmt->execute($params);
        $departmentCompliance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Asset type compliance
        $stmt = $pdo->prepare("
            SELECT 
                at.id,
                at.name as asset_type_name,
                COUNT(c.id) as total_checklists,
                SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) as completed_checklists,
                ROUND(
                    (SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) / 
                     NULLIF(COUNT(c.id), 0)) * 100, 1
                ) as compliance_rate
            FROM asset_types at
            LEFT JOIN assets a ON at.id = a.asset_type_id
            LEFT JOIN departments d ON a.department_id = d.id
            LEFT JOIN checklists c ON a.id = c.asset_id 
                AND c.created_at BETWEEN ? AND ?
            WHERE 1=1 $departmentFilter
            GROUP BY at.id, at.name
            HAVING total_checklists > 0
            ORDER BY compliance_rate DESC
        ");
        $stmt->execute($params);
        $assetTypeCompliance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'department_compliance' => $departmentCompliance,
                'asset_type_compliance' => $assetTypeCompliance,
                'period' => [
                    'from' => $filters['date_from'],
                    'to' => $filters['date_to']
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Get department performance metrics
 */
function getDepartmentPerformance() {
    global $pdo, $userId, $userRole;
    
    $departmentId = $_GET['department_id'] ?? null;
    
    if (!$departmentId) {
        sendJsonResponse(['success' => false, 'error' => 'Department ID required'], 400);
    }
    
    // Check permission for department access
    if (!in_array($userRole, ['superadmin', 'admin']) && $departmentId != $_SESSION['department_id']) {
        sendJsonResponse(['success' => false, 'error' => 'Access denied to this department'], 403);
    }
    
    try {
        // Department overview
        $stmt = $pdo->prepare("
            SELECT 
                d.id,
                d.name,
                d.description,
                COUNT(DISTINCT a.id) as total_assets,
                COUNT(DISTINCT u.id) as total_users,
                COUNT(DISTINCT c.id) as total_checklists_this_month,
                COUNT(DISTINCT n.id) as total_ncrs_this_month
            FROM departments d
            LEFT JOIN assets a ON d.id = a.department_id
            LEFT JOIN users u ON d.id = u.department_id AND u.is_active = 1
            LEFT JOIN checklists c ON a.id = c.asset_id 
                AND c.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            LEFT JOIN ncrs n ON d.id = n.department_id 
                AND n.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            WHERE d.id = ?
            GROUP BY d.id, d.name, d.description
        ");
        $stmt->execute([$departmentId]);
        $overview = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Recent activity (last 30 days)
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as activity_date,
                COUNT(*) as checklist_count
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            WHERE a.department_id = ? 
                AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY activity_date DESC
            LIMIT 30
        ");
        $stmt->execute([$departmentId]);
        $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'overview' => $overview,
                'recent_activity' => $recentActivity
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Get NCR analysis data
 */
function getNCRAnalysis() {
    global $pdo, $userId, $userRole;
    
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
        'date_to' => $_GET['date_to'] ?? date('Y-m-t'),
        'department_id' => $_GET['department_id'] ?? null
    ];
    
    $departmentFilter = '';
    $params = [$filters['date_from'], $filters['date_to']];
    
    if ($filters['department_id']) {
        $departmentFilter = ' AND n.department_id = ?';
        $params[] = $filters['department_id'];
    }
    
    if (!in_array($userRole, ['superadmin', 'admin'])) {
        $departmentFilter .= ' AND n.department_id = ?';
        $params[] = $_SESSION['department_id'];
    }
    
    try {
        // NCR by severity
        $stmt = $pdo->prepare("
            SELECT 
                severity,
                COUNT(*) as count,
                AVG(DATEDIFF(IFNULL(closed_at, NOW()), created_at)) as avg_resolution_days
            FROM ncrs n
            WHERE n.created_at BETWEEN ? AND ? $departmentFilter
            GROUP BY severity
            ORDER BY 
                CASE severity 
                    WHEN 'critical' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    WHEN 'low' THEN 4 
                END
        ");
        $stmt->execute($params);
        $severityBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // NCR by category
        $stmt = $pdo->prepare("
            SELECT 
                category,
                COUNT(*) as count
            FROM ncrs n
            WHERE n.created_at BETWEEN ? AND ? $departmentFilter
            GROUP BY category
            ORDER BY count DESC
        ");
        $stmt->execute($params);
        $categoryBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // NCR trends (last 6 months)
        $trends = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-$i months"));
            $monthEnd = date('Y-m-t', strtotime("-$i months"));
            $monthName = date('M Y', strtotime("-$i months"));
            
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                FROM ncrs n
                WHERE n.created_at BETWEEN ? AND ? $departmentFilter
            ");
            $monthParams = [$monthStart, $monthEnd];
            if ($filters['department_id']) {
                $monthParams[] = $filters['department_id'];
            }
            if (!in_array($userRole, ['superadmin', 'admin'])) {
                $monthParams[] = $_SESSION['department_id'];
            }
            $stmt->execute($monthParams);
            $monthData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $trends[] = [
                'month' => $monthName,
                'total_ncrs' => (int)$monthData['total'],
                'closed_ncrs' => (int)$monthData['closed']
            ];
        }
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'severity_breakdown' => $severityBreakdown,
                'category_breakdown' => $categoryBreakdown,
                'trends' => $trends,
                'period' => [
                    'from' => $filters['date_from'],
                    'to' => $filters['date_to']
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Get audit trail data
 */
function getAuditTrail() {
    global $pdo, $userId, $userRole;
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = ($page - 1) * $limit;
    
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')),
        'date_to' => $_GET['date_to'] ?? date('Y-m-d'),
        'entity_type' => $_GET['entity_type'] ?? null,
        'action_type' => $_GET['action_type'] ?? null,
        'user_id' => $_GET['user_id'] ?? null
    ];
    
    $conditions = ['al.created_at BETWEEN ? AND ?'];
    $params = [$filters['date_from'], $filters['date_to']];
    
    if ($filters['entity_type']) {
        $conditions[] = 'al.entity_type = ?';
        $params[] = $filters['entity_type'];
    }
    
    if ($filters['action_type']) {
        $conditions[] = 'al.action_type = ?';
        $params[] = $filters['action_type'];
    }
    
    if ($filters['user_id']) {
        $conditions[] = 'al.user_id = ?';
        $params[] = $filters['user_id'];
    }
    
    // Restrict to user's department if not admin
    if (!in_array($userRole, ['superadmin', 'admin'])) {
        $conditions[] = 'u.department_id = ?';
        $params[] = $_SESSION['department_id'];
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    try {
        // Get total count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE $whereClause
        ");
        $stmt->execute($params);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get audit logs
        $stmt = $pdo->prepare("
            SELECT 
                al.id,
                al.entity_type,
                al.entity_id,
                al.action_type,
                al.changes,
                al.ip_address,
                al.user_agent,
                al.created_at,
                u.name as user_name,
                u.email as user_email
            FROM audit_logs al
            JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process changes JSON
        foreach ($auditLogs as &$log) {
            $log['changes'] = json_decode($log['changes'], true);
        }
        
        $pagination = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ];
        
        sendJsonResponse([
            'success' => true,
            'data' => [
                'audit_logs' => $auditLogs,
                'pagination' => $pagination
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Check if user has reporting permission
 */
function hasReportingPermission($role) {
    return in_array($role, ['superadmin', 'admin', 'auditor', 'dept_manager']);
}

/**
 * Log API errors
 */
function logError($context, $message, $details = []) {
    error_log("$context: $message " . json_encode($details));
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}
?>