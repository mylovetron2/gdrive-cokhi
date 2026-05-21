<?php
/**
 * Dashboard Home
 */

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/Auth.php';
require_once APP_ROOT . '/includes/Permission.php';
require_once APP_ROOT . '/includes/Helper.php';
require_once APP_ROOT . '/includes/FileManager.php';
require_once APP_ROOT . '/includes/FolderManager.php';

$auth = new Auth();
$auth->requireLogin();

$permission = new Permission();
$fileManager = new FileManager();
$folderManager = new FolderManager();
$db = Database::getInstance();

// Get statistics
$totalFiles = $fileManager->getFileCount();

$db->query("SELECT COUNT(*) as count FROM folders_cokhi");
$totalFolders = $db->fetchColumn();

$db->query("SELECT COUNT(*) as count FROM users_cokhi WHERE status = 'active'");
$totalUsers = $db->fetchColumn();

$db->query("SELECT SUM(file_size) as total FROM files_cokhi");
$totalSize = $db->fetchColumn() ?? 0;

// Get recent files
$recentFiles = $fileManager->getAllFiles(null, 10, 0);

// Get recent activity
$userId = $auth->getCurrentUserId();
$db->query("
    SELECT al.*, u.username, u.full_name 
    FROM activity_logs_cokhi al
    LEFT JOIN users_cokhi u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 15
");
$recentActivity = $db->fetchAll();

$title = 'Dashboard';
$showNav = true;
$showSidebar = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="fas fa-dashboard me-2"></i>Dashboard</h2>
        <p class="text-muted">Chào mừng trở lại, <?php echo $_SESSION['full_name'] ?? $_SESSION['username']; ?>!</p>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Tổng Files</h6>
                        <h3 class="mb-0"><?php echo number_format($totalFiles); ?></h3>
                    </div>
                    <i class="fas fa-file fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Thư mục</h6>
                        <h3 class="mb-0"><?php echo number_format($totalFolders); ?></h3>
                    </div>
                    <i class="fas fa-folder fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Dung lượng</h6>
                        <h3 class="mb-0"><?php echo Helper::formatFileSize($totalSize); ?></h3>
                    </div>
                    <i class="fas fa-database fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Người dùng</h6>
                        <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                    </div>
                    <i class="fas fa-users fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Thao tác nhanh</h5>
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($permission->can('file.upload')): ?>
                        <a href="<?php echo APP_URL; ?>/views/dashboard/upload.php" class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i>Upload File
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($permission->can('folder.create')): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                            <i class="fas fa-folder-plus me-2"></i>Tạo Thư mục
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($permission->can('file.view')): ?>
                        <a href="<?php echo APP_URL; ?>/views/dashboard/files.php" class="btn btn-info">
                            <i class="fas fa-list me-2"></i>Danh sách Files
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($auth->isAdmin()): ?>
                        <a href="<?php echo APP_URL; ?>/views/admin/users.php" class="btn btn-secondary">
                            <i class="fas fa-users-cog me-2"></i>Quản lý Users
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Google Drive Storage Quota -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fab fa-google-drive me-2 text-primary"></i>Dung lượng Google Drive</h5>
                <button class="btn btn-sm btn-outline-primary" onclick="refreshStorageQuota()">
                    <i class="fas fa-refresh me-1"></i>Làm mới
                </button>
            </div>
            <div class="card-body" id="storageQuotaContainer">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Đang tải...</span>
                    </div>
                    <p class="text-muted mt-2">Đang tải thông tin dung lượng...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Files -->
    <div class="col-md-7 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file me-2"></i>Files gần đây</h5>
                <a href="<?php echo APP_URL; ?>/views/dashboard/files.php" class="btn btn-sm btn-outline-primary">Xem tất cả</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentFiles)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Chưa có file nào</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Tên file</th>
                                    <th>Kích thước</th>
                                    <th>Ngày tải lên</th>
                                    <th>Người tải</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentFiles as $file): ?>
                                    <tr>
                                        <td>
                                            <i class="fas <?php echo Helper::getFileIcon($file['file_extension']); ?> me-2"></i>
                                            <?php echo htmlspecialchars($file['file_name']); ?>
                                        </td>
                                        <td><?php echo Helper::formatFileSize($file['file_size']); ?></td>
                                        <td><?php echo Helper::timeAgo($file['uploaded_at']); ?></td>
                                        <td><?php echo htmlspecialchars($file['uploader_name'] ?? $file['uploader_username'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="col-md-5 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Hoạt động gần đây</h5>
            </div>
            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                <?php if (empty($recentActivity)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Chưa có hoạt động nào</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex w-100 justify-content-between">
                                    <small class="text-primary fw-bold">
                                        <?php echo htmlspecialchars($activity['full_name'] ?? $activity['username'] ?? 'System'); ?>
                                    </small>
                                    <small class="text-muted"><?php echo Helper::timeAgo($activity['created_at']); ?></small>
                                </div>
                                <p class="mb-0"><small><?php echo htmlspecialchars($activity['description']); ?></small></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create Folder Modal -->
<div class="modal fade" id="createFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Tạo thư mục mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?php echo APP_URL; ?>/api/folder-create.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="folderName" class="form-label">Tên thư mục</label>
                        <input type="text" class="form-control" id="folderName" name="folder_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo thư mục</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Load Google Drive Storage Quota on page load
document.addEventListener('DOMContentLoaded', function() {
    loadStorageQuota();
});

function loadStorageQuota() {
    const container = document.getElementById('storageQuotaContainer');
    
    fetch('<?php echo APP_URL; ?>/api/drive-storage.php')
        .then(response => {
            // Check if response is OK
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.text();
        })
        .then(text => {
            // Log raw response for debugging
            console.log('Storage API Response:', text);
            
            // Try to parse JSON
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response');
            }
            
            if (data.success && data.data && data.data.success) {
                displayStorageQuota(data.data);
            } else {
                // Show warning for not authenticated or other errors
                const isNotAuthenticated = data.data && data.data.authenticated === false;
                const errorMsg = data.message || 'Không thể tải thông tin dung lượng';
                const needsScope = errorMsg.includes('insufficient') || errorMsg.includes('scope') || errorMsg.includes('permission');
                
                container.innerHTML = `
                    <div class="alert alert-${isNotAuthenticated ? 'info' : 'warning'} mb-0">
                        <i class="fas fa-${isNotAuthenticated ? 'info-circle' : 'exclamation-triangle'} me-2"></i>
                        <strong>${errorMsg}</strong>
                        ${isNotAuthenticated || needsScope ? '<br><small class="mt-2 d-block">Vui lòng <a href="' + '<?php echo APP_URL; ?>' + '/views/dashboard/reauthorize-drive.php" class="alert-link">kết nối lại Google Drive</a> để xem thông tin dung lượng.</small>' : ''}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Storage quota error:', error);
            container.innerHTML = `
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>Lỗi kết nối server</strong>
                    <br><small>Chi tiết: ${error.message}</small>
                    <br><small class="mt-1 d-block">Vui lòng kiểm tra console để xem lỗi chi tiết.</small>
                </div>
            `;
        });
}

function displayStorageQuota(data) {
    const container = document.getElementById('storageQuotaContainer');
    
    const progressBarColor = data.used_percent > 90 ? 'bg-danger' : 
                             data.used_percent > 75 ? 'bg-warning' : 'bg-success';
    
    container.innerHTML = `
        <div class="row">
            <div class="col-md-8">
                <h6 class="mb-3">
                    <i class="fas fa-user-circle me-2"></i>${data.user_name || data.user_email}
                </h6>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Đã sử dụng</span>
                        <span class="fw-bold">${data.usage_formatted} / ${data.limit_formatted}</span>
                    </div>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar ${progressBarColor}" role="progressbar" 
                             style="width: ${data.used_percent}%;" 
                             aria-valuenow="${data.used_percent}" aria-valuemin="0" aria-valuemax="100">
                            ${data.used_percent}%
                        </div>
                    </div>
                </div>
                
                <div class="row text-center">
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <i class="fas fa-hdd fa-2x text-primary mb-2"></i>
                            <h6 class="mb-0">${data.usage_in_drive_formatted}</h6>
                            <small class="text-muted">Trong Drive</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <i class="fas fa-trash fa-2x text-warning mb-2"></i>
                            <h6 class="mb-0">${data.usage_in_trash_formatted}</h6>
                            <small class="text-muted">Thùng rác</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <h6 class="mb-0">${data.available_formatted}</h6>
                            <small class="text-muted">Còn trống</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card bg-light h-100">
                    <div class="card-body">
                        <h6 class="card-title mb-3">
                            <i class="fas fa-info-circle me-2"></i>Thông tin
                        </h6>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <i class="fas fa-database text-primary me-2"></i>
                                <strong>Tổng:</strong> ${data.limit_formatted}
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-chart-pie text-success me-2"></i>
                                <strong>Đã dùng:</strong> ${data.usage_formatted}
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-percentage text-info me-2"></i>
                                <strong>Tỷ lệ:</strong> ${data.used_percent}%
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-envelope text-secondary me-2"></i>
                                <small>${data.user_email}</small>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function refreshStorageQuota() {
    const container = document.getElementById('storageQuotaContainer');
    container.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Đang tải...</span>
            </div>
            <p class="text-muted mt-2">Đang làm mới thông tin...</p>
        </div>
    `;
    loadStorageQuota();
}
</script>

<?php include APP_ROOT . '/views/includes/footer.php'; ?>
