<?php
/**
 * File Upload API
 * Handles secure file uploads for evidence attachments
 */

require_once '../config.php';

// Set JSON header and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Check authentication
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        handleFileUpload();
        break;
    case 'GET':
        handleFileDownload();
        break;
    case 'DELETE':
        handleFileDelete();
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Handle file upload
 * POST /api/uploads.php
 * Multipart form data with file and metadata
 */
function handleFileUpload() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager', 'technician'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_FILES['file'])) {
        jsonResponse(['success' => false, 'error' => 'No file provided'], 400);
    }
    
    $file = $_FILES['file'];
    $entityType = isset($_POST['entity_type']) ? sanitize($_POST['entity_type']) : '';
    $entityId = isset($_POST['entity_id']) ? intval($_POST['entity_id']) : 0;
    
    if (!$entityType || !$entityId) {
        jsonResponse(['success' => false, 'error' => 'Entity type and ID required'], 400);
    }
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'File upload failed'], 400);
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonResponse(['success' => false, 'error' => 'File too large. Maximum size: 5MB'], 400);
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_MIME_TYPES)) {
        jsonResponse(['success' => false, 'error' => 'File type not allowed'], 400);
    }
    
    try {
        // Create uploads directory if it doesn't exist
        if (!is_dir(UPLOAD_PATH)) {
            mkdir(UPLOAD_PATH, 0755, true);
        }
        
        // Generate secure filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $storedFilename = uniqid('upload_', true) . '.' . $fileExtension;
        $filePath = UPLOAD_PATH . $storedFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            jsonResponse(['success' => false, 'error' => 'Failed to save file'], 500);
        }
        
        // Save file record to database
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO attachments (entity_type, entity_id, original_filename, stored_filename, 
                                   file_path, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $entityType, $entityId, $file['name'], $storedFilename,
            'uploads/' . $storedFilename, $mimeType, $file['size'], $_SESSION['user_id']
        ]);
        
        $attachmentId = $db->lastInsertId();
        
        // Update related entity with attachment reference
        updateEntityWithAttachment($entityType, $entityId, 'uploads/' . $storedFilename);
        
        // Log audit trail
        logAuditTrail('file_upload', 'attachment', $attachmentId, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'filename' => $file['name'],
            'size' => $file['size']
        ]);
        
        jsonResponse([
            'success' => true,
            'data' => [
                'attachment_id' => $attachmentId,
                'filename' => $file['name'],
                'stored_filename' => $storedFilename,
                'file_path' => 'uploads/' . $storedFilename,
                'size' => $file['size']
            ]
        ]);
        
    } catch (Exception $e) {
        // Clean up file if database save failed
        if (isset($filePath) && file_exists($filePath)) {
            unlink($filePath);
        }
        
        error_log("File upload error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'File upload failed'], 500);
    }
}

/**
 * Handle file download/access
 * GET /api/uploads.php?file=filename
 */
function handleFileDownload() {
    if (!isset($_GET['file'])) {
        jsonResponse(['success' => false, 'error' => 'File parameter required'], 400);
    }
    
    $storedFilename = sanitize($_GET['file']);
    $filePath = UPLOAD_PATH . $storedFilename;
    
    // Verify file exists and user has access
    if (!file_exists($filePath)) {
        jsonResponse(['success' => false, 'error' => 'File not found'], 404);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT original_filename, mime_type, file_size, entity_type, entity_id
            FROM attachments 
            WHERE stored_filename = ?
        ");
        $stmt->execute([$storedFilename]);
        $attachment = $stmt->fetch();
        
        if (!$attachment) {
            jsonResponse(['success' => false, 'error' => 'File record not found'], 404);
        }
        
        // Check if user has access to the entity
        if (!hasEntityAccess($attachment['entity_type'], $attachment['entity_id'])) {
            jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }
        
        // Serve the file
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: inline; filename="' . $attachment['original_filename'] . '"');
        header('Content-Length: ' . $attachment['file_size']);
        header('Cache-Control: no-cache, must-revalidate');
        
        readfile($filePath);
        exit;
        
    } catch (Exception $e) {
        error_log("File download error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'File access failed'], 500);
    }
}

/**
 * Handle file deletion
 * DELETE /api/uploads.php?id=attachment_id
 */
function handleFileDelete() {
    if (!hasRole(['superadmin', 'admin', 'auditor', 'dept_manager'])) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
    }
    
    if (!isset($_GET['id'])) {
        jsonResponse(['success' => false, 'error' => 'Attachment ID required'], 400);
    }
    
    $attachmentId = intval($_GET['id']);
    
    if (!$attachmentId) {
        jsonResponse(['success' => false, 'error' => 'Invalid attachment ID'], 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get attachment details
        $stmt = $db->prepare("
            SELECT stored_filename, file_path, entity_type, entity_id, original_filename
            FROM attachments 
            WHERE id = ?
        ");
        $stmt->execute([$attachmentId]);
        $attachment = $stmt->fetch();
        
        if (!$attachment) {
            jsonResponse(['success' => false, 'error' => 'Attachment not found'], 404);
        }
        
        // Check if user has access to delete
        if (!hasEntityAccess($attachment['entity_type'], $attachment['entity_id'])) {
            jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }
        
        // Delete file from filesystem
        $filePath = UPLOAD_PATH . $attachment['stored_filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete database record
        $stmt = $db->prepare("DELETE FROM attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        
        // Log audit trail
        logAuditTrail('file_delete', 'attachment', $attachmentId, [
            'entity_type' => $attachment['entity_type'],
            'entity_id' => $attachment['entity_id'],
            'filename' => $attachment['original_filename']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("File deletion error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'File deletion failed'], 500);
    }
}

/**
 * Update entity record with attachment reference
 * @param string $entityType Type of entity
 * @param int $entityId Entity ID
 * @param string $filePath File path to store
 */
function updateEntityWithAttachment($entityType, $entityId, $filePath) {
    $db = Database::getInstance()->getConnection();
    
    switch ($entityType) {
        case 'checklist_item':
            $stmt = $db->prepare("UPDATE checklist_items SET attached_file = ? WHERE id = ?");
            $stmt->execute([$filePath, $entityId]);
            break;
        case 'ncr':
            $stmt = $db->prepare("UPDATE ncrs SET evidence = ? WHERE id = ?");
            $stmt->execute([$filePath, $entityId]);
            break;
        // Add more entity types as needed
    }
}

/**
 * Check if user has access to specific entity
 * @param string $entityType Type of entity
 * @param int $entityId Entity ID
 * @return bool True if user has access, false otherwise
 */
function hasEntityAccess($entityType, $entityId) {
    // For now, allow access if user is logged in
    // In production, implement proper access control based on entity relationships
    if (hasRole(['superadmin', 'admin'])) {
        return true;
    }
    
    $db = Database::getInstance()->getConnection();
    
    switch ($entityType) {
        case 'checklist_item':
            // Check if user is related to the checklist
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM checklist_items ci
                JOIN checklists c ON ci.checklist_id = c.id
                WHERE ci.id = ? AND (c.created_by = ? OR c.assigned_to = ?)
            ");
            $stmt->execute([$entityId, $_SESSION['user_id'], $_SESSION['user_id']]);
            return $stmt->fetchColumn() > 0;
            
        case 'ncr':
            // Check if user is related to the NCR
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM ncrs 
                WHERE id = ? AND (raised_by = ? OR assigned_to = ?)
            ");
            $stmt->execute([$entityId, $_SESSION['user_id'], $_SESSION['user_id']]);
            return $stmt->fetchColumn() > 0;
    }
    
    return false;
}

// Create .htaccess file for upload security
if (!file_exists(UPLOAD_PATH . '.htaccess')) {
    $htaccess = "# Prevent direct access to uploaded files\n";
    $htaccess .= "Order Deny,Allow\n";
    $htaccess .= "Deny from all\n";
    $htaccess .= "\n";
    $htaccess .= "# Allow access only through PHP script\n";
    $htaccess .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|pdf|doc|docx|txt)$\">\n";
    $htaccess .= "    Order Allow,Deny\n";
    $htaccess .= "    Deny from all\n";
    $htaccess .= "</FilesMatch>\n";
    
    file_put_contents(UPLOAD_PATH . '.htaccess', $htaccess);
}
?>
