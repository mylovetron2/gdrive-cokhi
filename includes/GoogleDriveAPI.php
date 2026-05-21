<?php
/**
 * Google Drive API Integration
 * Handles all Google Drive operations using Google API PHP Client
 */

// Ensure Database class is loaded
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

class GoogleDriveAPI {
    private $client;
    private $service;
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->initializeClient();
    }
    
    /**
     * Initialize Google Client
     */
    private function initializeClient() {
        try {
            require_once APP_ROOT . '/vendor/autoload.php';
            
            $this->client = new Google_Client();
            $this->client->setClientId(GDRIVE_CLIENT_ID);
            $this->client->setClientSecret(GDRIVE_CLIENT_SECRET);
            $this->client->setRedirectUri(GDRIVE_REDIRECT_URI);
            
            // Support multiple scopes (space-separated)
            $scopes = explode(' ', GDRIVE_SCOPE);
            $this->client->setScopes($scopes);
            
            $this->client->setAccessType(GDRIVE_ACCESS_TYPE);
            $this->client->setPrompt('consent');
            
            // Load saved token if exists
            $this->loadToken();
            
            $this->service = new Google_Service_Drive($this->client);
            
        } catch (Exception $e) {
            error_log("Google Drive API initialization error: " . $e->getMessage());
            throw new Exception("Failed to initialize Google Drive API");
        }
    }
    
    /**
     * Load saved access token from database or file
     */
    private function loadToken() {
        $tokenFile = APP_ROOT . '/config/gdrive_token.json';
        
        if (file_exists($tokenFile)) {
            $accessToken = json_decode(file_get_contents($tokenFile), true);
            $this->client->setAccessToken($accessToken);
            
            // Refresh token if expired
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    $this->saveToken($this->client->getAccessToken());
                }
            }
        }
    }
    
    /**
     * Save access token
     */
    private function saveToken($token) {
        $tokenFile = APP_ROOT . '/config/gdrive_token.json';
        file_put_contents($tokenFile, json_encode($token));
    }
    
    /**
     * Get authorization URL
     */
    public function getAuthUrl() {
        return $this->client->createAuthUrl();
    }
    
    /**
     * Authenticate with authorization code
     */
    public function authenticate($authCode) {
        try {
            $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
            
            if (isset($accessToken['error'])) {
                throw new Exception($accessToken['error_description']);
            }
            
            $this->saveToken($accessToken);
            return true;
            
        } catch (Exception $e) {
            error_log("Google Drive authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if authenticated
     */
    public function isAuthenticated() {
        return $this->client->getAccessToken() && !$this->client->isAccessTokenExpired();
    }
    
    /**
     * Upload file to Google Drive
     */
    public function uploadFile($filePath, $fileName, $mimeType, $folderId = null, $description = '') {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $fileName,
                'description' => $description
            ]);
            
            if ($folderId) {
                $fileMetadata->setParents([$folderId]);
            } elseif (GDRIVE_ROOT_FOLDER_ID) {
                $fileMetadata->setParents([GDRIVE_ROOT_FOLDER_ID]);
            }
            
            $content = file_get_contents($filePath);
            
            $file = $this->service->files->create(
                $fileMetadata,
                [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, mimeType, size, webViewLink, webContentLink'
                ]
            );
            
            return [
                'success' => true,
                'file_id' => $file->getId(),
                'name' => $file->getName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'web_link' => $file->getWebViewLink(),
                'download_link' => $file->getWebContentLink()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive upload error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload large file with resumable upload
     */
    public function uploadLargeFile($filePath, $fileName, $mimeType, $folderId = null) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $fileName
            ]);
            
            if ($folderId) {
                $fileMetadata->setParents([$folderId]);
            }
            
            $this->client->setDefer(true);
            
            $request = $this->service->files->create($fileMetadata);
            
            $media = new Google_Http_MediaFileUpload(
                $this->client,
                $request,
                $mimeType,
                null,
                true,
                UPLOAD_CHUNK_SIZE
            );
            
            $media->setFileSize(filesize($filePath));
            
            $status = false;
            $handle = fopen($filePath, 'rb');
            
            while (!$status && !feof($handle)) {
                $chunk = fread($handle, UPLOAD_CHUNK_SIZE);
                $status = $media->nextChunk($chunk);
            }
            
            fclose($handle);
            $this->client->setDefer(false);
            
            if ($status !== false) {
                return [
                    'success' => true,
                    'file_id' => $status->getId(),
                    'name' => $status->getName(),
                    'size' => $status->getSize()
                ];
            }
            
            return ['success' => false, 'error' => 'Upload failed'];
            
        } catch (Exception $e) {
            error_log("Google Drive large upload error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Download file from Google Drive
     */
    public function downloadFile($fileId) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $response = $this->service->files->get($fileId, ['alt' => 'media']);
            
            return [
                'success' => true,
                'content' => $response->getBody()->getContents()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive download error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Delete file from Google Drive
     */
    public function deleteFile($fileId) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $this->service->files->delete($fileId);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Google Drive delete error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get file metadata
     */
    public function getFileMetadata($fileId) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $file = $this->service->files->get($fileId, [
                'fields' => 'id, name, mimeType, size, createdTime, modifiedTime, webViewLink, webContentLink'
            ]);
            
            return [
                'success' => true,
                'file' => [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'created' => $file->getCreatedTime(),
                    'modified' => $file->getModifiedTime(),
                    'web_link' => $file->getWebViewLink(),
                    'download_link' => $file->getWebContentLink()
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive metadata error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create folder in Google Drive
     */
    public function createFolder($folderName, $parentFolderId = null) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder'
            ]);
            
            if ($parentFolderId) {
                $fileMetadata->setParents([$parentFolderId]);
            } elseif (GDRIVE_ROOT_FOLDER_ID) {
                $fileMetadata->setParents([GDRIVE_ROOT_FOLDER_ID]);
            }
            
            $folder = $this->service->files->create($fileMetadata, [
                'fields' => 'id, name'
            ]);
            
            return [
                'success' => true,
                'folder_id' => $folder->getId(),
                'name' => $folder->getName()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive create folder error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * List files in a folder
     */
    public function listFiles($folderId = null, $pageSize = 100) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $query = "";
            if ($folderId) {
                $query = "'{$folderId}' in parents and trashed=false";
            } else {
                $query = "trashed=false";
            }
            
            $optParams = [
                'pageSize' => $pageSize,
                'fields' => 'files(id, name, mimeType, size, createdTime, modifiedTime)',
                'q' => $query
            ];
            
            $results = $this->service->files->listFiles($optParams);
            
            return [
                'success' => true,
                'files_cokhi' => $results->getFiles()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive list files error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Make file public and get shareable link
     */
    public function makePublic($fileId) {
        try {
            if (!$this->isAuthenticated()) {
                throw new Exception("Not authenticated with Google Drive");
            }
            
            $permission = new Google_Service_Drive_Permission([
                'type' => 'anyone',
                'role' => 'reader'
            ]);
            
            $this->service->permissions->create($fileId, $permission);
            
            $file = $this->service->files->get($fileId, ['fields' => 'webViewLink, webContentLink']);
            
            return [
                'success' => true,
                'web_link' => $file->getWebViewLink(),
                'download_link' => $file->getWebContentLink()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive make public error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get Drive storage quota information
     * Returns storage limit, usage, and available space
     */
    public function getStorageQuota() {
        try {
            if (!$this->isAuthenticated()) {
                return [
                    'success' => false,
                    'error' => 'Chưa xác thực với Google Drive'
                ];
            }
            
            // Get storage quota information
            $about = $this->service->about->get(['fields' => 'storageQuota,user']);
            $storageQuota = $about->getStorageQuota();
            $user = $about->getUser();
            
            $limit = $storageQuota->getLimit();
            $usage = $storageQuota->getUsage();
            $usageInDrive = $storageQuota->getUsageInDrive();
            $usageInDriveTrash = $storageQuota->getUsageInDriveTrash();
            
            // Calculate percentages and format
            $usedPercent = $limit > 0 ? round(($usage / $limit) * 100, 2) : 0;
            $availableSpace = $limit - $usage;
            
            return [
                'success' => true,
                'limit' => $limit,
                'limit_formatted' => $this->formatBytes($limit),
                'usage' => $usage,
                'usage_formatted' => $this->formatBytes($usage),
                'usage_in_drive' => $usageInDrive,
                'usage_in_drive_formatted' => $this->formatBytes($usageInDrive),
                'usage_in_trash' => $usageInDriveTrash,
                'usage_in_trash_formatted' => $this->formatBytes($usageInDriveTrash),
                'available' => $availableSpace,
                'available_formatted' => $this->formatBytes($availableSpace),
                'used_percent' => $usedPercent,
                'user_email' => $user->getEmailAddress(),
                'user_name' => $user->getDisplayName()
            ];
            
        } catch (Exception $e) {
            error_log("Google Drive storage quota error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Không thể lấy thông tin dung lượng: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2) {
        if ($bytes == 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $base = log($bytes, 1024);
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
    }
}
