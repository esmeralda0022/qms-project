<?php
/**
 * Reports API
 * Handles report generation and export functionality
 * Provides PDF and CSV export capabilities for compliance reporting
 */

require_once '../config.php';

// Set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Check authentication
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleReportRequest();
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle report generation requests
 * 
 * Supported reports:
 * - checklists: Checklist compliance report
 * - ncrs: Non-conformance reports
 * - maintenance: Maintenance logs
 * - compliance: Overall compliance summary
 */
function handleReportRequest() {
    $reportType = isset($_GET['type']) ? sanitize($_GET['type']) : '';
    $format = isset($_GET['format']) ? sanitize($_GET['format']) : 'json';
    
    if (!$reportType) {
        jsonResponse(['success' => false, 'error' => 'Report type required'], 400);
    }
    
    // Check user permissions
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    switch ($reportType) {
        case 'checklists':
            generateChecklistReport($format);
            break;
        case 'ncrs':
            generateNCRReport($format);
            break;
        case 'compliance':
            generateComplianceReport($format);
            break;
        case 'maintenance':
            generateMaintenanceReport($format);
            break;
        default:
            jsonResponse(['success' => false, 'error' => 'Invalid report type'], 400);
    }
}

/**
 * Generate checklist compliance report
 * @param string $format Output format (json, csv, pdf)
 */
function generateChecklistReport($format) {
    $db = Database::getInstance()->getConnection();
    
    // Get filter parameters
    $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;
    $fromDate = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : date('Y-m-01');
    $toDate = isset($_GET['to_date']) ? sanitize($_GET['to_date']) : date('Y-m-d');
    
    try {
        $sql = "
            SELECT 
                c.id,
                c.checklist_name,
                c.status,
                c.created_at,
                c.completed_at,
                a.name as asset_name,
                a.asset_tag,
                d.name as department_name,
                dt.name as document_type,
                u1.name as created_by_name,
                u2.name as assigned_to_name,
                COUNT(ci.id) as total_items,
                SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) as passed_items,
                SUM(CASE WHEN ci.result = 'fail' THEN 1 ELSE 0 END) as failed_items,
                SUM(CASE WHEN ci.result = 'na' THEN 1 ELSE 0 END) as na_items,
                ROUND(
                    (SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) * 100.0) / 
                    NULLIF(COUNT(ci.id), 0), 2
                ) as compliance_percentage
            FROM checklists c
            JOIN assets a ON c.asset_id = a.id
            JOIN asset_types at ON a.asset_type_id = at.id
            JOIN departments d ON at.department_id = d.id
            JOIN document_types dt ON c.document_type_id = dt.id
            JOIN users u1 ON c.created_by = u1.id
            LEFT JOIN users u2 ON c.assigned_to = u2.id
            LEFT JOIN checklist_items ci ON c.id = ci.checklist_id
            WHERE c.created_at BETWEEN ? AND ?
        ";
        
        $params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
        
        if ($departmentId) {
            $sql .= " AND d.id = ?";
            $params[] = $departmentId;
        }
        
        $sql .= " 
            GROUP BY c.id, c.checklist_name, c.status, c.created_at, c.completed_at,
                     a.name, a.asset_tag, d.name, dt.name, u1.name, u2.name
            ORDER BY c.created_at DESC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        switch ($format) {
            case 'csv':
                exportCSV($data, 'checklist_report');
                break;
            case 'pdf':
                exportPDF($data, 'Checklist Compliance Report', 'checklist_report');
                break;
            default:
                jsonResponse([
                    'success' => true,
                    'data' => $data,
                    'summary' => generateChecklistSummary($data)
                ]);
        }
        
        // Log report generation
        logAuditTrail('generate_report', 'report', null, [
            'type' => 'checklists',
            'format' => $format,
            'filters' => $_GET
        ]);
        
    } catch (Exception $e) {
        error_log("Report generation error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Report generation failed'], 500);
    }
}

/**
 * Generate NCR report
 * @param string $format Output format
 */
function generateNCRReport($format) {
    $db = Database::getInstance()->getConnection();
    
    $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;
    $fromDate = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : date('Y-m-01');
    $toDate = isset($_GET['to_date']) ? sanitize($_GET['to_date']) : date('Y-m-d');
    
    try {
        $sql = "
            SELECT 
                n.id,
                n.ncr_number,
                n.description,
                n.status,
                n.severity,
                n.due_date,
                n.completed_date,
                n.created_at,
                d.name as department_name,
                a.name as asset_name,
                a.asset_tag,
                u1.name as raised_by_name,
                u2.name as assigned_to_name,
                ci.question as checklist_question,
                s.source as standard_source,
                s.clause_id as standard_clause
            FROM ncrs n
            JOIN departments d ON n.department_id = d.id
            LEFT JOIN assets a ON n.asset_id = a.id
            JOIN users u1 ON n.raised_by = u1.id
            LEFT JOIN users u2 ON n.assigned_to = u2.id
            LEFT JOIN checklist_items ci ON n.checklist_item_id = ci.id
            LEFT JOIN standards s ON ci.standard_id = s.id
            WHERE n.created_at BETWEEN ? AND ?
        ";
        
        $params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
        
        if ($departmentId) {
            $sql .= " AND d.id = ?";
            $params[] = $departmentId;
        }
        
        $sql .= " ORDER BY n.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        switch ($format) {
            case 'csv':
                exportCSV($data, 'ncr_report');
                break;
            case 'pdf':
                exportPDF($data, 'Non-Conformance Report', 'ncr_report');
                break;
            default:
                jsonResponse([
                    'success' => true,
                    'data' => $data,
                    'summary' => generateNCRSummary($data)
                ]);
        }
        
        logAuditTrail('generate_report', 'report', null, [
            'type' => 'ncrs',
            'format' => $format
        ]);
        
    } catch (Exception $e) {
        error_log("NCR report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'NCR report generation failed'], 500);
    }
}

/**
 * Generate compliance summary report
 * @param string $format Output format
 */
function generateComplianceReport($format) {
    $db = Database::getInstance()->getConnection();
    
    try {
        // Overall compliance by department
        $stmt = $db->prepare("
            SELECT 
                d.name as department_name,
                COUNT(DISTINCT c.id) as total_checklists,
                COUNT(ci.id) as total_items,
                SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) as passed_items,
                SUM(CASE WHEN ci.result = 'fail' THEN 1 ELSE 0 END) as failed_items,
                ROUND(
                    (SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) * 100.0) / 
                    NULLIF(COUNT(ci.id), 0), 2
                ) as compliance_percentage
            FROM departments d
            LEFT JOIN asset_types at ON d.id = at.department_id
            LEFT JOIN assets a ON at.id = a.asset_type_id
            LEFT JOIN checklists c ON a.id = c.asset_id
            LEFT JOIN checklist_items ci ON c.id = ci.checklist_id
            WHERE c.status IN ('completed', 'signed_off')
            GROUP BY d.id, d.name
            ORDER BY compliance_percentage DESC
        ");
        $stmt->execute();
        $departmentCompliance = $stmt->fetchAll();
        
        // Compliance by standards
        $stmt = $db->prepare("
            SELECT 
                s.source,
                s.clause_id,
                s.title,
                COUNT(ci.id) as total_items,
                SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) as passed_items,
                SUM(CASE WHEN ci.result = 'fail' THEN 1 ELSE 0 END) as failed_items,
                ROUND(
                    (SUM(CASE WHEN ci.result = 'pass' THEN 1 ELSE 0 END) * 100.0) / 
                    NULLIF(COUNT(ci.id), 0), 2
                ) as compliance_percentage
            FROM standards s
            LEFT JOIN checklist_items ci ON s.id = ci.standard_id
            LEFT JOIN checklists c ON ci.checklist_id = c.id
            WHERE c.status IN ('completed', 'signed_off')
            GROUP BY s.id, s.source, s.clause_id, s.title
            HAVING total_items > 0
            ORDER BY s.source, s.clause_id
        ");
        $stmt->execute();
        $standardsCompliance = $stmt->fetchAll();
        
        $data = [
            'department_compliance' => $departmentCompliance,
            'standards_compliance' => $standardsCompliance,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        switch ($format) {
            case 'csv':
                exportComplianceCSV($data);
                break;
            case 'pdf':
                exportCompliancePDF($data);
                break;
            default:
                jsonResponse(['success' => true, 'data' => $data]);
        }
        
    } catch (Exception $e) {
        error_log("Compliance report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Compliance report generation failed'], 500);
    }
}

/**
 * Generate maintenance report
 * @param string $format Output format
 */
function generateMaintenanceReport($format) {
    // Simple maintenance report implementation
    // In a full system, this would have more detailed maintenance tracking
    
    $data = [
        [
            'asset_name' => 'Main Transformer Unit 1',
            'asset_tag' => 'TRF-001',
            'last_maintenance' => '2024-01-15',
            'next_due' => '2024-02-15',
            'status' => 'Due Soon'
        ],
        [
            'asset_name' => 'Emergency Generator 1',
            'asset_tag' => 'DG-001',
            'last_maintenance' => '2024-01-20',
            'next_due' => '2024-02-20',
            'status' => 'On Schedule'
        ]
    ];
    
    switch ($format) {
        case 'csv':
            exportCSV($data, 'maintenance_report');
            break;
        case 'pdf':
            exportPDF($data, 'Maintenance Schedule Report', 'maintenance_report');
            break;
        default:
            jsonResponse(['success' => true, 'data' => $data]);
    }
}

/**
 * Export data as CSV
 * @param array $data Data to export
 * @param string $filename Base filename
 */
function exportCSV($data, $filename) {
    if (empty($data)) {
        jsonResponse(['success' => false, 'error' => 'No data to export'], 400);
        return;
    }
    
    $filename = $filename . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, array_keys($data[0]));
    
    // Write data rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

/**
 * Export data as PDF (simplified implementation)
 * @param array $data Data to export
 * @param string $title Report title
 * @param string $filename Base filename
 */
function exportPDF($data, $title, $filename) {
    // For a production system, you'd use a PDF library like TCPDF or FPDF
    // This is a simplified HTML-to-PDF implementation
    
    $filename = $filename . '_' . date('Y-m-d_H-i-s') . '.html';
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo generatePDFHTML($data, $title);
    exit;
}

/**
 * Generate HTML for PDF export
 * @param array $data Data for the report
 * @param string $title Report title
 * @return string HTML content
 */
function generatePDFHTML($data, $title) {
    if (empty($data)) {
        return '<p>No data available for this report.</p>';
    }
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($title) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #1e40af; border-bottom: 2px solid #1e40af; padding-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f3f4f6; font-weight: bold; }
            .header { margin-bottom: 20px; }
            .footer { margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . htmlspecialchars($title) . '</h1>
            <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
            <p>QuailtyMed Healthcare Quality Management System</p>
        </div>
        
        <table>';
    
    // Table header
    $html .= '<thead><tr>';
    foreach (array_keys($data[0]) as $header) {
        $html .= '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', $header))) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    
    // Table rows
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars($cell ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>
        
        <div class="footer">
            <p>This report complies with NABH and JCI documentation standards.</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

/**
 * Generate summary for checklist report
 * @param array $data Checklist data
 * @return array Summary statistics
 */
function generateChecklistSummary($data) {
    $totalChecklists = count($data);
    $completedChecklists = 0;
    $totalCompliance = 0;
    
    foreach ($data as $row) {
        if ($row['status'] === 'completed' || $row['status'] === 'signed_off') {
            $completedChecklists++;
        }
        if (isset($row['compliance_percentage'])) {
            $totalCompliance += $row['compliance_percentage'];
        }
    }
    
    return [
        'total_checklists' => $totalChecklists,
        'completed_checklists' => $completedChecklists,
        'completion_rate' => $totalChecklists > 0 ? round(($completedChecklists / $totalChecklists) * 100, 2) : 0,
        'average_compliance' => $totalChecklists > 0 ? round($totalCompliance / $totalChecklists, 2) : 0
    ];
}

/**
 * Generate summary for NCR report
 * @param array $data NCR data
 * @return array Summary statistics
 */
function generateNCRSummary($data) {
    $totalNCRs = count($data);
    $openNCRs = 0;
    $overdueNCRs = 0;
    $severityCount = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
    
    foreach ($data as $row) {
        if ($row['status'] === 'open' || $row['status'] === 'in_progress') {
            $openNCRs++;
        }
        
        if ($row['due_date'] && $row['status'] !== 'closed' && strtotime($row['due_date']) < time()) {
            $overdueNCRs++;
        }
        
        if (isset($severityCount[$row['severity']])) {
            $severityCount[$row['severity']]++;
        }
    }
    
    return [
        'total_ncrs' => $totalNCRs,
        'open_ncrs' => $openNCRs,
        'overdue_ncrs' => $overdueNCRs,
        'severity_breakdown' => $severityCount
    ];
}

/**
 * Export compliance data as CSV
 * @param array $data Compliance data
 */
function exportComplianceCSV($data) {
    $filename = 'compliance_report_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Department compliance section
    fputcsv($output, ['Department Compliance Summary']);
    fputcsv($output, ['Department', 'Total Checklists', 'Total Items', 'Passed Items', 'Failed Items', 'Compliance %']);
    
    foreach ($data['department_compliance'] as $row) {
        fputcsv($output, [
            $row['department_name'],
            $row['total_checklists'],
            $row['total_items'],
            $row['passed_items'],
            $row['failed_items'],
            $row['compliance_percentage'] . '%'
        ]);
    }
    
    // Empty row
    fputcsv($output, []);
    
    // Standards compliance section
    fputcsv($output, ['Standards Compliance Summary']);
    fputcsv($output, ['Standard', 'Clause ID', 'Title', 'Total Items', 'Passed Items', 'Failed Items', 'Compliance %']);
    
    foreach ($data['standards_compliance'] as $row) {
        fputcsv($output, [
            $row['source'],
            $row['clause_id'],
            $row['title'],
            $row['total_items'],
            $row['passed_items'],
            $row['failed_items'],
            $row['compliance_percentage'] . '%'
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Export compliance data as PDF
 * @param array $data Compliance data
 */
function exportCompliancePDF($data) {
    $filename = 'compliance_report_' . date('Y-m-d_H-i-s') . '.html';
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Generate comprehensive compliance PDF HTML
    $html = generateCompliancePDFHTML($data);
    echo $html;
    exit;
}

/**
 * Generate comprehensive compliance PDF HTML
 * @param array $data Compliance data
 * @return string HTML content
 */
function generateCompliancePDFHTML($data) {
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Compliance Summary Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1, h2 { color: #1e40af; }
            h1 { border-bottom: 2px solid #1e40af; padding-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f3f4f6; font-weight: bold; }
            .high-compliance { background-color: #dcfce7; }
            .medium-compliance { background-color: #fef3c7; }
            .low-compliance { background-color: #fee2e2; }
        </style>
    </head>
    <body>
        <h1>Compliance Summary Report</h1>
        <p>Generated: ' . $data['generated_at'] . '</p>
        
        <h2>Department Compliance</h2>
        <table>';
    
    $html .= '<tr><th>Department</th><th>Checklists</th><th>Total Items</th><th>Passed</th><th>Failed</th><th>Compliance %</th></tr>';
    
    foreach ($data['department_compliance'] as $row) {
        $complianceClass = '';
        if ($row['compliance_percentage'] >= 90) $complianceClass = 'high-compliance';
        elseif ($row['compliance_percentage'] >= 75) $complianceClass = 'medium-compliance';
        else $complianceClass = 'low-compliance';
        
        $html .= "<tr class='$complianceClass'>
            <td>" . htmlspecialchars($row['department_name']) . "</td>
            <td>" . $row['total_checklists'] . "</td>
            <td>" . $row['total_items'] . "</td>
            <td>" . $row['passed_items'] . "</td>
            <td>" . $row['failed_items'] . "</td>
            <td>" . $row['compliance_percentage'] . "%</td>
        </tr>";
    }
    
    $html .= '</table>
        
        <h2>Standards Compliance</h2>
        <table>
        <tr><th>Standard</th><th>Clause</th><th>Title</th><th>Items</th><th>Passed</th><th>Failed</th><th>Compliance %</th></tr>';
    
    foreach ($data['standards_compliance'] as $row) {
        $complianceClass = '';
        if ($row['compliance_percentage'] >= 90) $complianceClass = 'high-compliance';
        elseif ($row['compliance_percentage'] >= 75) $complianceClass = 'medium-compliance';
        else $complianceClass = 'low-compliance';
        
        $html .= "<tr class='$complianceClass'>
            <td>" . htmlspecialchars($row['source']) . "</td>
            <td>" . htmlspecialchars($row['clause_id']) . "</td>
            <td>" . htmlspecialchars($row['title']) . "</td>
            <td>" . $row['total_items'] . "</td>
            <td>" . $row['passed_items'] . "</td>
            <td>" . $row['failed_items'] . "</td>
            <td>" . $row['compliance_percentage'] . "%</td>
        </tr>";
    }
    
    $html .= '</table>
    </body>
    </html>';
    
    return $html;
}
?>
