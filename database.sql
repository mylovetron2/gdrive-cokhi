-- Quáº£n lÃ½ file XSCTBDVL Database Schema
-- Created: 2026-04-16

-- Table: users_cokhi
CREATE TABLE users_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role_id INT,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: roles_cokhi
CREATE TABLE roles_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: permissions_cokhi
CREATE TABLE permissions_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(50) UNIQUE NOT NULL,
    permission_key VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    category VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: role_permissions_cokhi
CREATE TABLE role_permissions_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_permission (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles_cokhi(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions_cokhi(id) ON DELETE CASCADE,
    INDEX idx_role (role_id),
    INDEX idx_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_permissions_cokhi (override permissions_cokhi for specific users_cokhi)
CREATE TABLE user_permissions_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    granted BOOLEAN DEFAULT TRUE,
    granted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY unique_user_permission (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users_cokhi(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions_cokhi(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users_cokhi(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: folders_cokhi
CREATE TABLE folders_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_name VARCHAR(255) NOT NULL,
    gdrive_folder_id VARCHAR(255) UNIQUE,
    parent_id INT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES folders_cokhi(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users_cokhi(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id),
    INDEX idx_gdrive (gdrive_folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: files_cokhi
CREATE TABLE files_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    gdrive_file_id VARCHAR(255) UNIQUE NOT NULL,
    folder_id INT NULL,
    file_size BIGINT,
    mime_type VARCHAR(100),
    file_extension VARCHAR(10),
    gdrive_web_link TEXT,
    gdrive_download_link TEXT,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    download_count INT DEFAULT 0,
    FOREIGN KEY (folder_id) REFERENCES folders_cokhi(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users_cokhi(id) ON DELETE SET NULL,
    INDEX idx_folder (folder_id),
    INDEX idx_gdrive (gdrive_file_id),
    INDEX idx_uploader (uploaded_by),
    FULLTEXT idx_search (file_name, original_name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: shared_links_cokhi
CREATE TABLE shared_links_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    share_token VARCHAR(64) UNIQUE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    access_count INT DEFAULT 0,
    max_access INT NULL,
    password VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (file_id) REFERENCES files_cokhi(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users_cokhi(id) ON DELETE SET NULL,
    INDEX idx_token (share_token),
    INDEX idx_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: activity_logs_cokhi
CREATE TABLE activity_logs_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users_cokhi(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sessions_cokhi
CREATE TABLE sessions_cokhi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users_cokhi(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default roles_cokhi
INSERT INTO roles_cokhi (role_name, description, is_admin) VALUES
('Super Admin', 'Full system access with all permissions_cokhi', TRUE),
('Admin', 'Administrative access with management capabilities', TRUE),
('Manager', 'Can manage files_cokhi and users_cokhi', FALSE),
('Editor', 'Can upload, edit and delete files_cokhi', FALSE),
('Viewer', 'Can only view and download files_cokhi', FALSE);

-- Insert default permissions_cokhi
INSERT INTO permissions_cokhi (permission_name, permission_key, description, category) VALUES
-- File permissions_cokhi
('Upload files_cokhi', 'file.upload', 'Upload files_cokhi to Google Drive', 'files_cokhi'),
('Download files_cokhi', 'file.download', 'Download files_cokhi from Google Drive', 'files_cokhi'),
('Delete files_cokhi', 'file.delete', 'Delete files_cokhi from Google Drive', 'files_cokhi'),
('View files_cokhi', 'file.view', 'View file list and details', 'files_cokhi'),
('Edit files_cokhi', 'file.edit', 'Edit file information and metadata', 'files_cokhi'),
('Share files_cokhi', 'file.share', 'Create shareable links for files_cokhi', 'files_cokhi'),

-- Folder permissions_cokhi
('Create folders_cokhi', 'folder.create', 'Create new folders_cokhi', 'folders_cokhi'),
('Delete folders_cokhi', 'folder.delete', 'Delete folders_cokhi', 'folders_cokhi'),
('Manage folders_cokhi', 'folder.manage', 'Manage folder structure', 'folders_cokhi'),

-- User management permissions_cokhi
('View users_cokhi', 'user.view', 'View user list', 'users_cokhi'),
('Create users_cokhi', 'user.create', 'Create new users_cokhi', 'users_cokhi'),
('Edit users_cokhi', 'user.edit', 'Edit user information', 'users_cokhi'),
('Delete users_cokhi', 'user.delete', 'Delete users_cokhi', 'users_cokhi'),
('Manage roles_cokhi', 'user.manage_roles', 'Assign roles_cokhi to users_cokhi', 'users_cokhi'),
('Manage permissions_cokhi', 'user.manage_permissions', 'Grant or revoke permissions_cokhi', 'users_cokhi'),

-- System permissions_cokhi
('View Logs', 'system.view_logs', 'View activity logs', 'System'),
('System Settings', 'system.settings', 'Access system settings', 'System'),
('View Dashboard', 'system.dashboard', 'Access dashboard', 'System');

-- Assign permissions_cokhi to Super Admin (all permissions_cokhi)
INSERT INTO role_permissions_cokhi (role_id, permission_id)
SELECT 1, id FROM permissions_cokhi;

-- Assign permissions_cokhi to Admin
INSERT INTO role_permissions_cokhi (role_id, permission_id)
SELECT 2, id FROM permissions_cokhi WHERE permission_key IN (
    'file.upload', 'file.download', 'file.delete', 'file.view', 'file.edit', 'file.share',
    'folder.create', 'folder.delete', 'folder.manage',
    'user.view', 'user.create', 'user.edit', 'user.manage_roles',
    'system.view_logs', 'system.dashboard'
);

-- Assign permissions_cokhi to Manager
INSERT INTO role_permissions_cokhi (role_id, permission_id)
SELECT 3, id FROM permissions_cokhi WHERE permission_key IN (
    'file.upload', 'file.download', 'file.delete', 'file.view', 'file.edit', 'file.share',
    'folder.create', 'folder.delete', 'folder.manage',
    'user.view',
    'system.dashboard'
);

-- Assign permissions_cokhi to Editor
INSERT INTO role_permissions_cokhi (role_id, permission_id)
SELECT 4, id FROM permissions_cokhi WHERE permission_key IN (
    'file.upload', 'file.download', 'file.delete', 'file.view', 'file.edit',
    'folder.create',
    'system.dashboard'
);

-- Assign permissions_cokhi to Viewer
INSERT INTO role_permissions_cokhi (role_id, permission_id)
SELECT 5, id FROM permissions_cokhi WHERE permission_key IN (
    'file.download', 'file.view',
    'system.dashboard'
);

-- Create default admin user
-- Password: admin123 (hashed with PASSWORD_DEFAULT in PHP)
INSERT INTO users_cokhi (username, email, password, full_name, role_id, status) VALUES
('admin', 'admin@gdrive.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 1, 'active');

-- Create default root folder
INSERT INTO folders_cokhi (folder_name, gdrive_folder_id, parent_id, created_by) VALUES
('Root', NULL, NULL, 1);

-- Add foreign key constraint for users_cokhi.role_id
ALTER TABLE users_cokhi ADD FOREIGN KEY (role_id) REFERENCES roles_cokhi(id) ON DELETE SET NULL;
