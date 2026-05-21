<?php
/**
 * API: User Delete
 */

// Prevent any output before JSON
ob_start();

try {
    define('APP_ROOT', dirname(__DIR__));
    require_once APP_ROOT . '/config/config.php';
    require_once APP_ROOT . '/config/database.php';
    require_once APP_ROOT . '/includes/Auth.php';
    require_once APP_ROOT . '/includes/Helper.php';
    
    // Clear any output from includes
    ob_clean();
    
    header('Content-Type: application/json');
    
    $auth = new Auth();
    
    if (!$auth->isLoggedIn()) {
        Helper::jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        exit;
    }
    
    if (!$auth->isAdmin()) {
        Helper::jsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Helper::jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
        exit;
    }
    
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($userId <= 0) {
        Helper::jsonResponse(['success' => false, 'message' => 'Invalid user ID'], 200);
        exit;
    }
    
    // Prevent deleting super admin or yourself
    if ($userId == 1) {
        Helper::jsonResponse(['success' => false, 'message' => 'Cannot delete super admin'], 200);
        exit;
    }
    
    if ($userId == $_SESSION['user_id']) {
        Helper::jsonResponse(['success' => false, 'message' => 'Cannot delete yourself'], 200);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check if user exists
    $db->query("SELECT username FROM users_cokhi WHERE id = :id");
    $db->bind(':id', $userId);
    $user = $db->fetch();
    
    if (!$user) {
        Helper::jsonResponse(['success' => false, 'message' => 'User not found'], 200);
        exit;
    }
    
    // Delete user
    $result = $db->delete('users_cokhi', ['id' => $userId]);
    
    if ($result) {
        Helper::jsonResponse(['success' => true, 'message' => 'User deleted successfully'], 200);
    } else {
        Helper::jsonResponse(['success' => false, 'message' => 'Failed to delete user'], 200);
    }
    
} catch (Exception $e) {
    ob_clean();
    error_log("User delete API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
ob_end_flush();
