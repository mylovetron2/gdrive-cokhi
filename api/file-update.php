<?php
/**
 * API: File Update
 * Update file information (description, etc.)
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/Auth.php';
require_once APP_ROOT . '/includes/Permission.php';
require_once APP_ROOT . '/includes/Helper.php';

header('Content-Type: application/json');

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    Helper::jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$permission = new Permission();

if (!$permission->can('file.upload')) {
    Helper::jsonResponse(['success' => false, 'message' => 'You do not have permission to update files_cokhi'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helper::jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

if (!isset($_POST['file_id'])) {
    Helper::jsonResponse(['success' => false, 'message' => 'File ID is required']);
}

try {
    $db = Database::getInstance();
    $fileId = (int)$_POST['file_id'];
    
    // Check if file exists
    $db->query("SELECT id, uploaded_by FROM files_cokhi WHERE id = :id");
    $db->bind(':id', $fileId);
    $file = $db->fetch();
    
    if (!$file) {
        Helper::jsonResponse(['success' => false, 'message' => 'File not found'], 404);
    }
    
    // Check if user owns the file or is admin
    $userId = $auth->getCurrentUserId();
    $isAdmin = $permission->can('file.delete'); // Admins can delete, so they can also edit
    
    if ($file['uploaded_by'] != $userId && !$isAdmin) {
        Helper::jsonResponse(['success' => false, 'message' => 'You can only edit your own files'], 403);
    }
    
    // Update description
    $description = $_POST['description'] ?? '';
    
    $db->query("UPDATE files_cokhi SET description = :description, updated_at = NOW() WHERE id = :id");
    $db->bind(':description', $description);
    $db->bind(':id', $fileId);
    
    if ($db->execute()) {
        // Log activity
        $db->query("
            INSERT INTO activity_logs_cokhi (user_id, action, entity_type, entity_id, description, ip_address, user_agent) 
            VALUES (:user_id, 'file_updated', 'file', :file_id, :description, :ip, :user_agent)
        ");
        $db->bind(':user_id', $userId);
        $db->bind(':file_id', $fileId);
        $db->bind(':description', 'Updated file description');
        $db->bind(':ip', $_SERVER['REMOTE_ADDR']);
        $db->bind(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $db->execute();
        
        Helper::jsonResponse(['success' => true, 'message' => 'File description updated successfully'], 200);
    } else {
        Helper::jsonResponse(['success' => false, 'message' => 'Failed to update file'], 500);
    }
    
} catch (Exception $e) {
    error_log("File update API error: " . $e->getMessage());
    Helper::jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
