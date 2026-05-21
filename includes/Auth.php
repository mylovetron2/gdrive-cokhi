<?php
/**
 * Authentication Class
 * Handles user authentication, sessions, and login/logout
 */

// Ensure Database class is loaded
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

class Auth {
    private $db;
    private static $currentUser = null;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->startSession();
    }
    
    /**
     * Start secure session
     */
    private function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['last_regeneration'])) {
                $this->regenerateSession();
            } elseif (time() - $_SESSION['last_regeneration'] > 3600) {
                $this->regenerateSession();
            }
        }
    }
    
    /**
     * Regenerate session ID
     */
    private function regenerateSession() {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
    
    /**
     * Login user
     */
    public function login($username, $password, $remember = false) {
        try {
            // Get user from database
            $this->db->query("
                SELECT u.*, r.role_name, r.is_admin 
                FROM users_cokhi u
                LEFT JOIN roles_cokhi r ON u.role_id = r.id
                WHERE u.username = :username AND u.status = 'active'
            ");
            $this->db->bind(':username', $username);
            
            $user = $this->db->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password'])) {
                // Log failed login attempt
                $this->logActivity(null, 'login_failed', 'user', $user['id'], "Failed login attempt for user: {$username}");
                return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng'];
            }
            
            // Create session
            $this->createUserSession($user);
            
            // Update last login
            $this->db->update('users_cokhi', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
            
            // Log successful login
            $this->logActivity($user['id'], 'login', 'user', $user['id'], "User logged in: {$username}");
            
            return ['success' => true, 'message' => 'Đăng nhập thành công'];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Đã xảy ra lỗi khi đăng nhập'];
        }
    }
    
    /**
     * Create user session
     */
    private function createUserSession($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        
        // Store session in database
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $this->db->insert('sessions_cokhi', [
            'user_id' => $user['id'],
            'session_token' => $sessionToken,
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'expires_at' => $expiresAt
        ]);
        
        $_SESSION['session_token'] = $sessionToken;
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $username = $_SESSION['username'];
            
            // Log logout
            $this->logActivity($userId, 'logout', 'user', $userId, "User logged out: {$username}");
            
            // Delete session from database
            if (isset($_SESSION['session_token'])) {
                $this->db->delete('sessions_cokhi', ['session_token' => $_SESSION['session_token']]);
            }
        }
        
        // Clear session
        $_SESSION = [];
        
        // Destroy session cookie
        if (isset($_COOKIE[SESSION_NAME])) {
            setcookie(SESSION_NAME, '', time() - 3600, '/');
        }
        
        // Destroy session
        session_destroy();
        
        return true;
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time'])) {
            if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
                $this->logout();
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Require login
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . APP_URL . '/views/auth/login.php');
            exit;
        }
    }
    
    /**
     * Require admin
     */
    public function requireAdmin() {
        $this->requireLogin();
        
        if (!$this->isAdmin()) {
            header('Location: ' . APP_URL . '/index.php?error=access_denied');
            exit;
        }
    }
    
    /**
     * Check if current user is admin
     */
    public function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        if (self::$currentUser === null) {
            $this->db->query("
                SELECT u.*, r.role_name, r.is_admin 
                FROM users_cokhi u
                LEFT JOIN roles_cokhi r ON u.role_id = r.id
                WHERE u.id = :user_id
            ");
            $this->db->bind(':user_id', $_SESSION['user_id']);
            self::$currentUser = $this->db->fetch();
        }
        
        return self::$currentUser;
    }
    
    /**
     * Get current user ID
     */
    public function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Register new user
     */
    public function register($username, $email, $password, $fullName, $roleId = 5) {
        try {
            // Check if username exists
            $this->db->query("SELECT id FROM users_cokhi WHERE username = :username");
            $this->db->bind(':username', $username);
            
            if ($this->db->fetch()) {
                return ['success' => false, 'message' => 'Tên đăng nhập đã tồn tại'];
            }
            
            // Check if email exists
            $this->db->query("SELECT id FROM users_cokhi WHERE email = :email");
            $this->db->bind(':email', $email);
            
            if ($this->db->fetch()) {
                return ['success' => false, 'message' => 'Email đã tồn tại'];
            }
            
            // Hash password
            $hashedPassword = password_hash($password, HASH_ALGORITHM, ['cost' => HASH_COST]);
            
            // Insert user
            $result = $this->db->insert('users_cokhi', [
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
                'full_name' => $fullName,
                'role_id' => $roleId,
                'status' => 'active'
            ]);
            
            if ($result) {
                $userId = $this->db->lastInsertId();
                $this->logActivity(null, 'user_registered', 'user', $userId, "New user registered: {$username}");
                
                return ['success' => true, 'message' => 'Đăng ký thành công', 'user_id' => $userId];
            }
            
            return ['success' => false, 'message' => 'Đăng ký thất bại'];
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Đã xảy ra lỗi khi đăng ký'];
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $oldPassword, $newPassword) {
        try {
            // Get current password
            $this->db->query("SELECT password FROM users_cokhi WHERE id = :user_id");
            $this->db->bind(':user_id', $userId);
            $user = $this->db->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Người dùng không tồn tại'];
            }
            
            // Verify old password
            if (!password_verify($oldPassword, $user['password'])) {
                return ['success' => false, 'message' => 'Mật khẩu cũ không đúng'];
            }
            
            // Hash new password
            $hashedPassword = password_hash($newPassword, HASH_ALGORITHM, ['cost' => HASH_COST]);
            
            // Update password
            $result = $this->db->update('users_cokhi', ['password' => $hashedPassword], ['id' => $userId]);
            
            if ($result) {
                $this->logActivity($userId, 'password_changed', 'user', $userId, "Password changed");
                return ['success' => true, 'message' => 'Đổi mật khẩu thành công'];
            }
            
            return ['success' => false, 'message' => 'Đổi mật khẩu thất bại'];
            
        } catch (Exception $e) {
            error_log("Change password error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Đã xảy ra lỗi khi đổi mật khẩu'];
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }
    
    /**
     * Log activity
     */
    private function logActivity($userId, $action, $entityType, $entityId, $description) {
        try {
            $this->db->insert('activity_logs_cokhi', [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'ip_address' => $this->getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
}
