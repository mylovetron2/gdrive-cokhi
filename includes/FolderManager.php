<?php
/**
 * Folder Manager Class  
 * Handles folder operations with Google Drive
 */

// Ensure Database class is loaded
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

class FolderManager {
    private $db;
    private $auth;
    private $permission;
    private $gdrive;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->permission = new Permission();
        $this->gdrive = new GoogleDriveAPI();
    }
    
    /**
     * Create folder
     */
    public function createFolder($folderName, $parentId = null) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('folder.create')) {
                return ['success' => false, 'message' => 'You do not have permission to create folders'];
            }
            
            // Get parent folder's Google Drive ID if specified
            $gdriveParentId = null;
            if ($parentId) {
                $parentFolder = $this->getFolder($parentId);
                if ($parentFolder) {
                    $gdriveParentId = $parentFolder['gdrive_folder_id'];
                }
            }
            
            // Create folder in Google Drive
            $createResult = $this->gdrive->createFolder($folderName, $gdriveParentId);
            
            if (!$createResult['success']) {
                return ['success' => false, 'message' => $createResult['error'] ?? $createResult['message'] ?? 'Google Drive folder creation failed'];
            }
            
            // Save to database
            $folderData = [
                'folder_name' => $folderName,
                'gdrive_folder_id' => $createResult['folder_id'],
                'parent_id' => $parentId,
                'created_by' => $userId
            ];
            
            $this->db->insert('folders_cokhi', $folderData);
            $folderId = $this->db->lastInsertId();
            
            // Log activity
            $this->logActivity($userId, 'folder_created', 'folder', $folderId,
                "Created folder: {$folderName}");
            
            return [
                'success' => true,
                'message' => 'Folder created successfully',
                'folder_id' => $folderId,
                'gdrive_folder_id' => $createResult['folder_id']
            ];
            
        } catch (Exception $e) {
            error_log("Folder create error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error creating folder'];
        }
    }
    
    /**
     * Delete folder
     */
    public function deleteFolder($folderId) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('folder.delete')) {
                return ['success' => false, 'message' => 'You do not have permission to delete folders'];
            }
            
            // Get folder info
            $folder = $this->getFolder($folderId);
            
            if (!$folder) {
                return ['success' => false, 'message' => 'Folder not found'];
            }
            
            // Check if folder has files or subfolders
            $fileCount = $this->getFolderFileCount($folderId);
            $subfolderCount = $this->getSubfolderCount($folderId);
            
            if ($fileCount > 0 || $subfolderCount > 0) {
                return ['success' => false, 'message' => 'Cannot delete folder with files or subfolders'];
            }
            
            // Delete from Google Drive
            if ($folder['gdrive_folder_id']) {
                $deleteResult = $this->gdrive->deleteFile($folder['gdrive_folder_id']);
                
                if (!$deleteResult['success']) {
                    error_log("Google Drive delete folder failed for folder ID {$folderId}");
                }
            }
            
            // Delete from database
            $this->db->delete('folders_cokhi', ['id' => $folderId]);
            
            // Log activity
            $this->logActivity($userId, 'folder_deleted', 'folder', $folderId,
                "Deleted folder: {$folder['folder_name']}");
            
            return ['success' => true, 'message' => 'Folder deleted successfully'];
            
        } catch (Exception $e) {
            error_log("Folder delete error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting folder'];
        }
    }
    
    /**
     * Get folder by ID
     */
    public function getFolder($folderId) {
        try {
            $this->db->query("
                SELECT f.*, u.username as creator_username, u.full_name as creator_name,
                       p.folder_name as parent_name
                FROM folders_cokhi f
                LEFT JOIN users_cokhi u ON f.created_by = u.id
                LEFT JOIN folders_cokhi p ON f.parent_id = p.id
                WHERE f.id = :id
            ");
            $this->db->bind(':id', $folderId);
            return $this->db->fetch();
            
        } catch (Exception $e) {
            error_log("Get folder error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all folders
     */
    public function getAllFolders($parentId = null) {
        try {
            $sql = "
                SELECT f.*, 
                       u.username as creator_username, 
                       u.full_name as creator_name,
                       p.folder_name as parent_name,
                       COALESCE((SELECT COUNT(*) FROM files_cokhi WHERE folder_id = f.id), 0) as file_count,
                       COALESCE((SELECT COUNT(*) FROM folders_cokhi WHERE parent_id = f.id), 0) as subfolder_count
                FROM folders_cokhi f
                LEFT JOIN users_cokhi u ON f.created_by = u.id
                LEFT JOIN folders_cokhi p ON f.parent_id = p.id
            ";
            
            if ($parentId === null) {
                $sql .= " WHERE f.parent_id IS NULL";
            } else {
                $sql .= " WHERE f.parent_id = :parent_id";
            }
            
            $sql .= " ORDER BY f.folder_name ASC";
            
            $this->db->query($sql);
            
            if ($parentId !== null) {
                $this->db->bind(':parent_id', $parentId);
            }
            
            return $this->db->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get all folders error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get folder tree
     */
    public function getFolderTree($parentId = null, $depth = 0) {
        try {
            $folders = $this->getAllFolders($parentId);
            $tree = [];
            
            foreach ($folders as $folder) {
                $folder['depth'] = $depth;
                $folder['file_count'] = $this->getFolderFileCount($folder['id']);
                $folder['subfolder_count'] = $this->getSubfolderCount($folder['id']);
                $folder['children'] = $this->getFolderTree($folder['id'], $depth + 1);
                $tree[] = $folder;
            }
            
            return $tree;
            
        } catch (Exception $e) {
            error_log("Get folder tree error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get folder file count
     */
    private function getFolderFileCount($folderId) {
        try {
            $this->db->query("SELECT COUNT(*) as count FROM files_cokhi WHERE folder_id = :folder_id");
            $this->db->bind(':folder_id', $folderId);
            $result = $this->db->fetch();
            return $result['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log("Get folder file count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get subfolder count
     */
    private function getSubfolderCount($folderId) {
        try {
            $this->db->query("SELECT COUNT(*) as count FROM folders_cokhi WHERE parent_id = :parent_id");
            $this->db->bind(':parent_id', $folderId);
            $result = $this->db->fetch();
            return $result['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log("Get subfolder count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update folder
     */
    public function updateFolder($folderId, $data) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('folder.manage')) {
                return ['success' => false, 'message' => 'Bạn không có quyền quản lý thư mục'];
            }
            
            $result = $this->db->update('folders_cokhi', $data, ['id' => $folderId]);
            
            if ($result) {
                $this->logActivity($userId, 'folder_updated', 'folder', $folderId,
                    "Updated folder information");
                
                return ['success' => true, 'message' => 'Cập nhật thư mục thành công'];
            }
            
            return ['success' => false, 'message' => 'Không thể cập nhật thư mục'];
            
        } catch (Exception $e) {
            error_log("Update folder error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Lỗi khi cập nhật thư mục'];
        }
    }
    
    /**
     * Move folder
     */
    public function moveFolder($folderId, $newParentId) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('folder.manage')) {
                return ['success' => false, 'message' => 'You do not have permission to manage folders'];
            }
            
            // Check for circular reference
            if ($this->isCircularReference($folderId, $newParentId)) {
                return ['success' => false, 'message' => 'Cannot move folder into its own subfolder'];
            }
            
            $result = $this->db->update('folders_cokhi', ['parent_id' => $newParentId], ['id' => $folderId]);
            
            if ($result) {
                $this->logActivity($userId, 'folder_moved', 'folder', $folderId,
                    "Moved folder to new parent");
                
                return ['success' => true, 'message' => 'Folder moved successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to move folder'];
            
        } catch (Exception $e) {
            error_log("Move folder error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error moving folder'];
        }
    }
    
    /**
     * Check for circular reference
     */
    private function isCircularReference($folderId, $newParentId) {
        if ($folderId == $newParentId) {
            return true;
        }
        
        $currentId = $newParentId;
        while ($currentId !== null) {
            $folder = $this->getFolder($currentId);
            if (!$folder) {
                break;
            }
            
            if ($folder['id'] == $folderId) {
                return true;
            }
            
            $currentId = $folder['parent_id'];
        }
        
        return false;
    }
    
    /**
     * Get folder breadcrumb
     */
    public function getBreadcrumb($folderId) {
        try {
            $breadcrumb = [];
            $currentId = $folderId;
            
            while ($currentId !== null) {
                $folder = $this->getFolder($currentId);
                if (!$folder) {
                    break;
                }
                
                array_unshift($breadcrumb, [
                    'id' => $folder['id'],
                    'name' => $folder['folder_name']
                ]);
                
                $currentId = $folder['parent_id'];
            }
            
            return $breadcrumb;
            
        } catch (Exception $e) {
            error_log("Get breadcrumb error: " . $e->getMessage());
            return [];
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
