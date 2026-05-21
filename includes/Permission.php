<?php
/**
 * Permission Management Class
 * Handles user permissions and role-based access control
 */

// Ensure Database class is loaded
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

class Permission {
    private $db;
    private $auth;
    private static $userPermissions = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission($userId, $permissionKey) {
        try {
            // Admin always has all permissions
            if ($this->isUserAdmin($userId)) {
                return true;
            }
            
            // Check cache first
            if (isset(self::$userPermissions[$userId])) {
                return in_array($permissionKey, self::$userPermissions[$userId]);
            }
            
            // Load user permissions
            $permissions = $this->getUserPermissions($userId);
            self::$userPermissions[$userId] = $permissions;
            
            return in_array($permissionKey, $permissions);
            
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all permissions for a user
     */
    public function getUserPermissions($userId) {
        try {
            $permissions = [];
            
            // Get permissions from role
            $this->db->query("
                SELECT DISTINCT p.permission_key
                FROM users_cokhi u
                INNER JOIN roles_cokhi r ON u.role_id = r.id
                INNER JOIN role_permissions_cokhi rp ON r.id = rp.role_id
                INNER JOIN permissions_cokhi p ON rp.permission_id = p.id
                WHERE u.id = :user_id AND u.status = 'active'
            ");
            $this->db->bind(':user_id', $userId);
            $rolePermissions = $this->db->fetchAll();
            
            foreach ($rolePermissions as $perm) {
                $permissions[] = $perm['permission_key'];
            }
            
            // Get user-specific permissions (overrides)
            $this->db->query("
                SELECT p.permission_key, up.granted
                FROM user_permissions_cokhi up
                INNER JOIN permissions_cokhi p ON up.permission_id = p.id
                WHERE up.user_id = :user_id 
                AND (up.expires_at IS NULL OR up.expires_at > NOW())
            ");
            $this->db->bind(':user_id', $userId);
            $userPerms = $this->db->fetchAll();
            
            foreach ($userPerms as $perm) {
                if ($perm['granted']) {
                    // Add permission if granted
                    if (!in_array($perm['permission_key'], $permissions)) {
                        $permissions[] = $perm['permission_key'];
                    }
                } else {
                    // Remove permission if revoked
                    $permissions = array_diff($permissions, [$perm['permission_key']]);
                }
            }
            
            return $permissions;
            
        } catch (Exception $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if current user has permission
     */
    public function can($permissionKey) {
        $userId = $this->auth->getCurrentUserId();
        if (!$userId) {
            return false;
        }
        
        return $this->hasPermission($userId, $permissionKey);
    }
    
    /**
     * Require permission for current user
     */
    public function requirePermission($permissionKey, $redirectUrl = null) {
        if (!$this->can($permissionKey)) {
            if ($redirectUrl) {
                header('Location: ' . $redirectUrl);
            } else {
                header('HTTP/1.1 403 Forbidden');
                die('Access Denied: You do not have permission to perform this action.');
            }
            exit;
        }
    }
    
    /**
     * Grant permission to user
     */
    public function grantPermission($userId, $permissionKey, $grantedBy, $expiresAt = null) {
        try {
            // Get permission ID
            $this->db->query("SELECT id FROM permissions_cokhi WHERE permission_key = :key");
            $this->db->bind(':key', $permissionKey);
            $permission = $this->db->fetch();
            
            if (!$permission) {
                return ['success' => false, 'message' => 'Permission not found'];
            }
            
            $permissionId = $permission['id'];
            
            // Check if permission already exists
            $this->db->query("
                SELECT id FROM user_permissions_cokhi 
                WHERE user_id = :user_id AND permission_id = :permission_id
            ");
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':permission_id', $permissionId);
            $existing = $this->db->fetch();
            
            if ($existing) {
                // Update existing
                $result = $this->db->update('user_permissions_cokhi', [
                    'granted' => 1,
                    'granted_by' => $grantedBy,
                    'expires_at' => $expiresAt
                ], ['id' => $existing['id']]);
            } else {
                // Insert new
                $result = $this->db->insert('user_permissions_cokhi', [
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'granted' => 1,
                    'granted_by' => $grantedBy,
                    'expires_at' => $expiresAt
                ]);
            }
            
            if ($result) {
                // Clear cache
                unset(self::$userPermissions[$userId]);
                
                // Log activity
                $this->logActivity($grantedBy, 'permission_granted', 'user', $userId, 
                    "Permission '{$permissionKey}' granted to user ID {$userId}");
                
                return ['success' => true, 'message' => 'Permission granted successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to grant permission'];
            
        } catch (Exception $e) {
            error_log("Grant permission error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error granting permission'];
        }
    }
    
    /**
     * Revoke permission from user
     */
    public function revokePermission($userId, $permissionKey, $revokedBy) {
        try {
            // Get permission ID
            $this->db->query("SELECT id FROM permissions_cokhi WHERE permission_key = :key");
            $this->db->bind(':key', $permissionKey);
            $permission = $this->db->fetch();
            
            if (!$permission) {
                return ['success' => false, 'message' => 'Permission not found'];
            }
            
            $permissionId = $permission['id'];
            
            // Check if permission exists
            $this->db->query("
                SELECT id FROM user_permissions_cokhi 
                WHERE user_id = :user_id AND permission_id = :permission_id
            ");
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':permission_id', $permissionId);
            $existing = $this->db->fetch();
            
            if ($existing) {
                // Update to revoked
                $result = $this->db->update('user_permissions_cokhi', [
                    'granted' => 0,
                    'granted_by' => $revokedBy
                ], ['id' => $existing['id']]);
            } else {
                // Insert as revoked (to override role permission)
                $result = $this->db->insert('user_permissions_cokhi', [
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'granted' => 0,
                    'granted_by' => $revokedBy
                ]);
            }
            
            if ($result) {
                // Clear cache
                unset(self::$userPermissions[$userId]);
                
                // Log activity
                $this->logActivity($revokedBy, 'permission_revoked', 'user', $userId, 
                    "Permission '{$permissionKey}' revoked from user ID {$userId}");
                
                return ['success' => true, 'message' => 'Permission revoked successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to revoke permission'];
            
        } catch (Exception $e) {
            error_log("Revoke permission error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error revoking permission'];
        }
    }
    
    /**
     * Get all permissions
     */
    public function getAllPermissions() {
        try {
            $this->db->query("
                SELECT * FROM permissions_cokhi 
                ORDER BY category, permission_name
            ");
            return $this->db->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get all permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get permissions grouped by category
     */
    public function getPermissionsByCategory() {
        try {
            $permissions = $this->getAllPermissions();
            $grouped = [];
            
            foreach ($permissions as $permission) {
                $category = $permission['category'] ?? 'Other';
                if (!isset($grouped[$category])) {
                    $grouped[$category] = [];
                }
                $grouped[$category][] = $permission;
            }
            
            return $grouped;
            
        } catch (Exception $e) {
            error_log("Get permissions by category error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get role permissions
     */
    public function getRolePermissions($roleId) {
        try {
            $this->db->query("
                SELECT p.*
                FROM permissions_cokhi p
                INNER JOIN role_permissions_cokhi rp ON p.id = rp.permission_id
                WHERE rp.role_id = :role_id
                ORDER BY p.category, p.permission_name
            ");
            $this->db->bind(':role_id', $roleId);
            return $this->db->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get role permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Assign permission to role
     */
    public function assignPermissionToRole($roleId, $permissionId) {
        try {
            // Check if already assigned
            $this->db->query("
                SELECT id FROM role_permissions_cokhi 
                WHERE role_id = :role_id AND permission_id = :permission_id
            ");
            $this->db->bind(':role_id', $roleId);
            $this->db->bind(':permission_id', $permissionId);
            
            if ($this->db->fetch()) {
                return ['success' => false, 'message' => 'Permission already assigned to role'];
            }
            
            $result = $this->db->insert('role_permissions_cokhi', [
                'role_id' => $roleId,
                'permission_id' => $permissionId
            ]);
            
            if ($result) {
                return ['success' => true, 'message' => 'Permission assigned to role'];
            }
            
            return ['success' => false, 'message' => 'Failed to assign permission'];
            
        } catch (Exception $e) {
            error_log("Assign permission to role error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error assigning permission'];
        }
    }
    
    /**
     * Remove permission from role
     */
    public function removePermissionFromRole($roleId, $permissionId) {
        try {
            $result = $this->db->delete('role_permissions_cokhi', [
                'role_id' => $roleId,
                'permission_id' => $permissionId
            ]);
            
            if ($result) {
                return ['success' => true, 'message' => 'Permission removed from role'];
            }
            
            return ['success' => false, 'message' => 'Failed to remove permission'];
            
        } catch (Exception $e) {
            error_log("Remove permission from role error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error removing permission'];
        }
    }
    
    /**
     * Check if user is admin
     */
    private function isUserAdmin($userId) {
        try {
            $this->db->query("
                SELECT r.is_admin
                FROM users_cokhi u
                INNER JOIN roles_cokhi r ON u.role_id = r.id
                WHERE u.id = :user_id
            ");
            $this->db->bind(':user_id', $userId);
            $result = $this->db->fetch();
            
            return $result && $result['is_admin'] == 1;
            
        } catch (Exception $e) {
            error_log("Check admin error: " . $e->getMessage());
            return false;
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
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
}
