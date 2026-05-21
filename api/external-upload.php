<?php
/**
 * External Upload API
 * Cho phép project khác upload file lên Google Drive của Cơ Khí qua API key.
 *
 * Cách dùng từ project khác:
 *   POST https://diavatly.cloud/gdrive-cokhi/api/external-upload.php
 *   Header: Authorization: Bearer <EXTERNAL_API_KEY>
 *   Body (multipart/form-data):
 *     file         - file cần upload (required)
 *     folder_id    - ID thư mục trong gdrive-cokhi (optional, integer)
 *     description  - mô tả file (optional)
 *     uploader_ref - tên / ID người dùng bên project kia để ghi log (optional)
 *
 * Response JSON khi thành công:
 *   { "success": true, "file_id": 123, "gdrive_file_id": "...", "web_link": "...", "download_link": "..." }
 *
 * Response JSON khi lỗi:
 *   { "success": false, "message": "..." }
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/Helper.php';
require_once APP_ROOT . '/includes/Auth.php';
require_once APP_ROOT . '/includes/Permission.php';
require_once APP_ROOT . '/includes/FileManager.php';
require_once APP_ROOT . '/includes/GoogleDriveAPI.php';

// --- CORS headers (cho phép project khác gọi) ---
$allowedOrigins = [
    // Thêm domain của project kia vào đây, ví dụ:
    // 'https://diavatly.cloud',
    // 'https://other-project.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Nếu danh sách trống → cho phép tất cả (chỉ dùng trong môi trường nội bộ)
    if (empty($allowedOrigins)) {
        header('Access-Control-Allow-Origin: *');
    }
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Chỉ nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helper::jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    exit;
}

// --- Xác thực API key ---
$apiKey = null;

// Ưu tiên Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    $apiKey = $m[1];
}

// Fallback: POST field api_key
if (!$apiKey && !empty($_POST['api_key'])) {
    $apiKey = $_POST['api_key'];
}

if (!$apiKey || !hash_equals(EXTERNAL_API_KEY, $apiKey)) {
    Helper::jsonResponse(['success' => false, 'message' => 'Invalid or missing API key'], 401);
    exit;
}

if (empty(EXTERNAL_API_KEY)) {
    Helper::jsonResponse(['success' => false, 'message' => 'External API not configured on server'], 503);
    exit;
}

// --- Kiểm tra file ---
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File vượt quá upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'File vượt quá MAX_FILE_SIZE trong form',
        UPLOAD_ERR_PARTIAL    => 'File chỉ được upload một phần',
        UPLOAD_ERR_NO_FILE    => 'Không có file được gửi lên',
        UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm',
        UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file tạm',
        UPLOAD_ERR_EXTENSION  => 'Upload bị chặn bởi PHP extension',
    ];
    $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errMsg  = $uploadErrors[$errCode] ?? 'Upload error code ' . $errCode;
    Helper::jsonResponse(['success' => false, 'message' => $errMsg], 400);
    exit;
}

// --- Upload ---
try {
    $folderId    = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $uploaderRef = trim($_POST['uploader_ref'] ?? 'external');

    // Tạo Auth giả để FileManager không bị lỗi thiếu user
    // FileManager cần getCurrentUserId() — dùng user hệ thống (admin, user_id = 1)
    $auth = new Auth();

    // Nếu chưa có session, set user hệ thống vào session tạm để FileManager hoạt động
    if (!$auth->isLoggedIn()) {
        $db = Database::getInstance();
        $db->query("SELECT id FROM users_cokhi WHERE username = 'admin' AND status = 'active' LIMIT 1");
        $adminUser = $db->fetch();
        if (!$adminUser) {
            Helper::jsonResponse(['success' => false, 'message' => 'System user not found'], 500);
            exit;
        }
        // Mở session tạm (không dùng cookie session của người dùng thật)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $adminUser['id'];
        $_SESSION['external_api_call'] = true; // Đánh dấu để phân biệt
    }

    $fileManager = new FileManager();
    $result = $fileManager->uploadFile($_FILES['file'], $folderId, $description);

    // Ghi thêm uploader_ref vào log nếu upload thành công
    if ($result['success'] && !empty($uploaderRef) && $uploaderRef !== 'external') {
        try {
            $db = Database::getInstance();
            $db->query("UPDATE activity_logs_cokhi SET description = CONCAT(description, ' [by: ', ?, ']') WHERE entity_type = 'file' AND entity_id = ? ORDER BY id DESC LIMIT 1");
            $db->execute([$uploaderRef, $result['file_id']]);
        } catch (Exception $ignored) {}
    }

    // Xóa session tạm nếu đã tạo
    if (!empty($_SESSION['external_api_call'])) {
        session_destroy();
    }

    Helper::jsonResponse($result, $result['success'] ? 200 : 422);

} catch (Exception $e) {
    error_log("External Upload API error: " . $e->getMessage());
    Helper::jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
