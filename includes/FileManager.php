<?php
/**
 * File Manager Class
 * Handles file operations with Google Drive
 */

// Ensure Database class is loaded
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

class FileManager {
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
     * Upload file
     */
    public function uploadFile($file, $folderId = null, $description = '') {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('file.upload')) {
                return ['success' => false, 'message' => 'You do not have permission to upload files'];
            }
            
            // Validate file
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                return ['success' => false, 'message' => 'Invalid file upload'];
            }
            
            // Check file size
            if ($file['size'] > MAX_UPLOAD_SIZE) {
                return ['success' => false, 'message' => 'File size exceeds limit'];
            }
            
            // Check file extension
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = explode(',', ALLOWED_EXTENSIONS);
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                return ['success' => false, 'message' => 'File type not allowed'];
            }
            
            // Get folder's Google Drive ID if folder ID provided
            $gdriveFolderId = null;
            if ($folderId) {
                $folder = $this->getFolder($folderId);
                if ($folder) {
                    $gdriveFolderId = $folder['gdrive_folder_id'];
                }
            }
            
            // Upload to Google Drive
            $uploadResult = $this->gdrive->uploadFile(
                $file['tmp_name'],
                $file['name'],
                $file['type'],
                $gdriveFolderId,
                $description
            );
            
            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => $uploadResult['error'] ?? $uploadResult['message'] ?? 'Google Drive upload failed'];
            }
            
            // Save file metadata to database
            $fileData = [
                'file_name' => $file['name'],
                'original_name' => $file['name'],
                'gdrive_file_id' => $uploadResult['file_id'],
                'folder_id' => $folderId,
                'file_size' => $file['size'],
                'mime_type' => $file['type'],
                'file_extension' => $fileExtension,
                'gdrive_web_link' => $uploadResult['web_link'] ?? null,
                'gdrive_download_link' => $uploadResult['download_link'] ?? null,
                'uploaded_by' => $userId,
                'description' => $description
            ];
            
            $this->db->insert('files_cokhi', $fileData);
            $fileId = $this->db->lastInsertId();
            
            // Log activity
            $this->logActivity($userId, 'file_uploaded', 'file', $fileId, 
                "Uploaded file: {$file['name']} (" . $this->formatFileSize($file['size']) . ")");
            
            return [
                'success' => true,
                'message' => 'File uploaded successfully',
                'file_id' => $fileId,
                'gdrive_file_id' => $uploadResult['file_id'],
                'web_link' => $uploadResult['web_link'] ?? null,
                'download_link' => $uploadResult['download_link'] ?? null,
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'mime_type' => $file['type']
            ];
            
        } catch (Exception $e) {
            error_log("File upload error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error uploading file'];
        }
    }
    
    /**
     * Download file
     */
    public function downloadFile($fileId) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('file.download')) {
                return ['success' => false, 'message' => 'You do not have permission to download files'];
            }
            
            // Get file info
            $file = $this->getFile($fileId);
            
            if (!$file) {
                return ['success' => false, 'message' => 'File not found'];
            }
            
            // Download from Google Drive
            $downloadResult = $this->gdrive->downloadFile($file['gdrive_file_id']);
            
            if (!$downloadResult['success']) {
                return $downloadResult;
            }
            
            // Update download count
            $this->db->query("UPDATE files_cokhi SET download_count = download_count + 1 WHERE id = :id");
            $this->db->bind(':id', $fileId);
            $this->db->execute();
            
            // Log activity
            $this->logActivity($userId, 'file_downloaded', 'file', $fileId, 
                "Downloaded file: {$file['file_name']}");
            
            return [
                'success' => true,
                'content' => $downloadResult['content'],
                'filename' => $file['file_name'],
                'mime_type' => $file['mime_type']
            ];
            
        } catch (Exception $e) {
            error_log("File download error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error downloading file'];
        }
    }
    
    /**
     * Delete file
     */
    public function deleteFile($fileId) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('file.delete')) {
                return ['success' => false, 'message' => 'You do not have permission to delete files'];
            }
            
            // Get file info
            $file = $this->getFile($fileId);
            
            if (!$file) {
                return ['success' => false, 'message' => 'File not found'];
            }
            
            // Delete from Google Drive
            $deleteResult = $this->gdrive->deleteFile($file['gdrive_file_id']);
            
            if (!$deleteResult['success']) {
                // Even if Google Drive delete fails, remove from database
                error_log("Google Drive delete failed for file ID {$fileId}, but removing from database");
            }
            
            // Delete from database
            $this->db->delete('files_cokhi', ['id' => $fileId]);
            
            // Log activity
            $this->logActivity($userId, 'file_deleted', 'file', $fileId, 
                "Deleted file: {$file['file_name']}");
            
            return ['success' => true, 'message' => 'File deleted successfully'];
            
        } catch (Exception $e) {
            error_log("File delete error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting file'];
        }
    }
    
    /**
     * Get file by ID
     */
    public function getFile($fileId) {
        try {
            $this->db->query("
                SELECT f.*, u.username as uploader_username, u.full_name as uploader_name,
                       fo.folder_name
                FROM files_cokhi f
                LEFT JOIN users_cokhi u ON f.uploaded_by = u.id
                LEFT JOIN folders_cokhi fo ON f.folder_id = fo.id
                WHERE f.id = :id
            ");
            $this->db->bind(':id', $fileId);
            return $this->db->fetch();
            
        } catch (Exception $e) {
            error_log("Get file error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all files
     */
    public function getAllFiles($folderId = null, $limit = 100, $offset = 0) {
        try {
            $sql = "
                SELECT f.*, u.username as uploader_username, u.full_name as uploader_name,
                       fo.folder_name
                FROM files_cokhi f
                LEFT JOIN users_cokhi u ON f.uploaded_by = u.id
                LEFT JOIN folders_cokhi fo ON f.folder_id = fo.id
            ";
            
            if ($folderId !== null) {
                $sql .= " WHERE f.folder_id = :folder_id";
            }
            
            $sql .= " ORDER BY f.uploaded_at DESC LIMIT :limit OFFSET :offset";
            
            $this->db->query($sql);
            
            if ($folderId !== null) {
                $this->db->bind(':folder_id', $folderId);
            }
            
            $this->db->bind(':limit', (int)$limit, PDO::PARAM_INT);
            $this->db->bind(':offset', (int)$offset, PDO::PARAM_INT);
            
            return $this->db->fetchAll();
            
        } catch (Exception $e) {
            error_log("Get all files error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search files
     */
    public function searchFiles($keyword, $limit = 100) {
        try {
            // Sử dụng LIKE search để tránh yêu cầu FULLTEXT index
            $this->db->query("
                SELECT f.*, u.username as uploader_username, u.full_name as uploader_name,
                       fo.folder_name
                FROM files_cokhi f
                LEFT JOIN users_cokhi u ON f.uploaded_by = u.id
                LEFT JOIN folders_cokhi fo ON f.folder_id = fo.id
                WHERE f.file_name LIKE :keyword1
                   OR f.original_name LIKE :keyword2
                   OR f.description LIKE :keyword3
                ORDER BY f.uploaded_at DESC
                LIMIT :limit
            ");
            
            $likeKeyword = '%' . $keyword . '%';
            $this->db->bind(':keyword1', $likeKeyword);
            $this->db->bind(':keyword2', $likeKeyword);
            $this->db->bind(':keyword3', $likeKeyword);
            $this->db->bind(':limit', (int)$limit, PDO::PARAM_INT);
            
            return $this->db->fetchAll();
            
        } catch (Exception $e) {
            error_log("Search files error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get file count
     */
    public function getFileCount($folderId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM files_cokhi";
            
            if ($folderId !== null) {
                $sql .= " WHERE folder_id = :folder_id";
            }
            
            $this->db->query($sql);
            
            if ($folderId !== null) {
                $this->db->bind(':folder_id', $folderId);
            }
            
            $result = $this->db->fetch();
            return $result['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log("Get file count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update file info
     */
    public function updateFile($fileId, $data) {
        try {
            $userId = $this->auth->getCurrentUserId();
            
            if (!$userId) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
            
            // Check permission
            if (!$this->permission->can('file.edit')) {
                return ['success' => false, 'message' => 'You do not have permission to edit files'];
            }
            
            $result = $this->db->update('files_cokhi', $data, ['id' => $fileId]);
            
            if ($result) {
                $this->logActivity($userId, 'file_updated', 'file', $fileId, 
                    "Updated file information");
                
                return ['success' => true, 'message' => 'File updated successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to update file'];
            
        } catch (Exception $e) {
            error_log("Update file error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error updating file'];
        }
    }
    
    /**
     * Get folder info
     */
    private function getFolder($folderId) {
        try {
            $this->db->query("SELECT * FROM folders_cokhi WHERE id = :id");
            $this->db->bind(':id', $folderId);
            return $this->db->fetch();
            
        } catch (Exception $e) {
            error_log("Get folder error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Format file size
     */
    private function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
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
