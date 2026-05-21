<?php
/**
 * Main Configuration File
 * Quản lý file Cơ Khí
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Load local configuration (credentials)
$localConfigFile = __DIR__ . '/config.local.php';
if (!file_exists($localConfigFile)) {
    die('Configuration error: config.local.php not found. Please copy config.local.example.php to config.local.php and update with your credentials.');
}
$localConfig = require $localConfigFile;

// Application Settings
define('APP_NAME', 'Quản lý file Cơ Khí');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://diavatly.cloud/gdrive-cokhi'); // Production domain with HTTPS

// Environment
define('ENVIRONMENT', 'development'); // development or production
define('DEBUG_MODE', true);

// Database Configuration
define('DB_HOST', $localConfig['DB_HOST']);
define('DB_NAME', $localConfig['DB_NAME']);
define('DB_USER', $localConfig['DB_USER']);
define('DB_PASS', $localConfig['DB_PASS']);
define('DB_CHARSET', 'utf8mb4');

// Session Configuration
define('SESSION_NAME', 'GDRIVE_CK_SESSION');
define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('SESSION_COOKIE_LIFETIME', 0); // Until browser closes

// Security
define('HASH_ALGORITHM', PASSWORD_DEFAULT);
define('HASH_COST', 10);
define('ENCRYPTION_KEY', $localConfig['ENCRYPTION_KEY']); // Loaded from local config
define('EXTERNAL_API_KEY', $localConfig['EXTERNAL_API_KEY'] ?? ''); // API key for external integrations

// Upload Settings
define('MAX_UPLOAD_SIZE', 104857600); // 100MB in bytes
define('ALLOWED_EXTENSIONS', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,mp4,mp3');
define('UPLOAD_TEMP_DIR', APP_ROOT . '/uploads/temp');
define('UPLOAD_CHUNK_SIZE', 5242880); // 5MB chunks for large files

// Google Drive API Configuration
define('GDRIVE_CLIENT_ID', $localConfig['GOOGLE_CLIENT_ID']);
define('GDRIVE_CLIENT_SECRET', $localConfig['GOOGLE_CLIENT_SECRET']);
define('GDRIVE_REDIRECT_URI', APP_URL . '/api/google-callback.php');
define('GDRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.metadata.readonly');
define('GDRIVE_ACCESS_TYPE', 'offline');
define('GDRIVE_ROOT_FOLDER_ID', ''); // Leave empty to use root or specify folder ID

// Google Account (for reference)
define('GOOGLE_ACCOUNT_EMAIL', ''); // Cập nhật email tài khoản Google Drive Cơ Khí
// Note: Password should not be stored here. Use OAuth2 authentication instead.

// Pagination
define('ITEMS_PER_PAGE', 20);
define('MAX_PAGINATION_LINKS', 5);

// Date & Time
define('TIMEZONE', 'Asia/Ho_Chi_Minh');
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i:s');
define('TIME_FORMAT', 'H:i:s');

// Logging
define('LOG_PATH', APP_ROOT . '/logs');
define('LOG_FILE', LOG_PATH . '/app_' . date('Y-m-d') . '.log');
define('LOG_LEVEL', 'info'); // debug, info, warning, error

// Activity Log Settings
define('ACTIVITY_LOG_RETENTION_DAYS', 90);

// File Sharing
define('SHARE_LINK_EXPIRY_DAYS', 7);
define('SHARE_LINK_TOKEN_LENGTH', 32);

// API Rate Limiting (optional)
define('API_RATE_LIMIT', 100); // requests per minute
define('API_RATE_LIMIT_WINDOW', 60); // seconds

// Error Reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOG_PATH . '/php_errors.log');
}

// Global exception handler for uncaught database/runtime exceptions
set_exception_handler(function($e) {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        die("Database Error: " . $e->getMessage());
    } else {
        error_log("Uncaught exception: " . $e->getMessage());
        die("An error occurred. Please try again later.");
    }
});

// Set timezone
date_default_timezone_set(TIMEZONE);

// Set default charset
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

// Session security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1); // Set to 1 when using HTTPS
ini_set('session.use_strict_mode', 1);

// Create required directories if they don't exist
$requiredDirs = [
    APP_ROOT . '/logs',
    APP_ROOT . '/uploads',
    APP_ROOT . '/uploads/temp'
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Autoload classes (simple autoloader)
spl_autoload_register(function ($class) {
    $classFile = APP_ROOT . '/includes/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
