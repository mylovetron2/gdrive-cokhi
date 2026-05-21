<?php
/**
 * API: Get File View Link
 * Get the web view link for a file
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/Auth.php';
require_once APP_ROOT . '/includes/Permission.php';
require_once APP_ROOT . '/includes/Helper.php';
require_once APP_ROOT . '/includes/GoogleDriveAPI.php';

header('Content-Type: application/json');

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    Helper::jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$permission = new Permission();

if (!$permission->can('file.view')) {
    Helper::jsonResponse(['success' => false, 'message' => 'You do not have permission to view files'], 403);
}

if (!isset($_GET['id'])) {
    Helper::jsonResponse(['success' => false, 'message' => 'File ID is required']);
}

try {
    $db = Database::getInstance();
    $fileId = (int)$_GET['id'];
    
    // Get file info
    $db->query("SELECT * FROM files_cokhi WHERE id = :id");
    $db->bind(':id', $fileId);
    $file = $db->fetch();
    
    if (!$file) {
        Helper::jsonResponse(['success' => false, 'message' => 'File not found'], 404);
    }
    
    // If web link already exists, return it
    if (!empty($file['gdrive_web_link'])) {
        Helper::jsonResponse([
            'success' => true,
            'web_link' => $file['gdrive_web_link'],
            'file_name' => $file['file_name']
        ]);
    }
    
    // Otherwise, fetch from Google Drive
    $gdrive = new GoogleDriveAPI();
    $links = $gdrive->getFileLinks($file['gdrive_file_id']);
    
    if ($links['success']) {
        // Update database with the links
        $db->query("
            UPDATE files_cokhi 
            SET gdrive_web_link = :web_link, 
                gdrive_download_link = :download_link,
                updated_at = NOW()
            WHERE id = :id
        ");
        $db->bind(':web_link', $links['web_link']);
        $db->bind(':download_link', $links['download_link']);
        $db->bind(':id', $fileId);
        $db->execute();
        
        Helper::jsonResponse([
            'success' => true,
            'web_link' => $links['web_link'],
            'file_name' => $file['file_name']
        ]);
    } else {
        Helper::jsonResponse(['success' => false, 'message' => 'Failed to get file link']);
    }
    
} catch (Exception $e) {
    error_log("File view API error: " . $e->getMessage());
    Helper::jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
