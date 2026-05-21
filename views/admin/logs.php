<?php
/**
 * Admin - Activity Logs
 */

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/Auth.php';
require_once APP_ROOT . '/includes/Permission.php';
require_once APP_ROOT . '/includes/Helper.php';

$auth = new Auth();
$auth->requireAdmin();

$permission = new Permission();
$db = Database::getInstance();

// Filters
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterAction = isset($_GET['action']) ? Helper::sanitize($_GET['action']) : '';
$filterDate = isset($_GET['date']) ? Helper::sanitize($_GET['date']) : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$itemsPerPage = 50;
$offset = ($page - 1) * $itemsPerPage;

// Build query
$whereConditions = [];
$params = [];

if ($filterUser > 0) {
    $whereConditions[] = "al.user_id = :user_id";
    $params[':user_id'] = $filterUser;
}

if (!empty($filterAction)) {
    $whereConditions[] = "al.action = :action";
    $params[':action'] = $filterAction;
}

if (!empty($filterDate)) {
    $whereConditions[] = "DATE(al.created_at) = :date";
    $params[':date'] = $filterDate;
}

$whereSQL = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count
$db->query("SELECT COUNT(*) as total FROM activity_logs_cokhi al $whereSQL");
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$totalResult = $db->fetch();
$totalLogs = $totalResult['total'];
$totalPages = ceil($totalLogs / $itemsPerPage);

// Get logs
$db->query("
    SELECT al.*, 
           u.username, u.full_name,
           CASE 
               WHEN al.entity_type = 'file' THEN (SELECT original_name FROM files_cokhi WHERE id = al.entity_id)
               WHEN al.entity_type = 'folder' THEN (SELECT folder_name FROM folders_cokhi WHERE id = al.entity_id)
               WHEN al.entity_type = 'user' THEN (SELECT username FROM users_cokhi WHERE id = al.entity_id)
               ELSE NULL
           END as entity_name
    FROM activity_logs_cokhi al
    LEFT JOIN users_cokhi u ON al.user_id = u.id
    $whereSQL
    ORDER BY al.created_at DESC
    LIMIT $itemsPerPage OFFSET $offset
");
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$logs = $db->fetchAll();

// Get all users for filter dropdown
$db->query("SELECT id, username, full_name FROM users_cokhi ORDER BY username");
$users = $db->fetchAll();

// Get unique actions for filter
$db->query("SELECT DISTINCT action FROM activity_logs_cokhi ORDER BY action");
$actions = $db->fetchAll();

$title = 'Lịch sử hoạt động';
$showNav = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="fas fa-history me-2"></i>Lịch sử hoạt động</h2>
    </div>
</div>

<!-- Filters -->
<div class="row mb-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <form method="get" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Người dùng</label>
                        <select name="user_id" class="form-select">
                            <option value="">-- Tất cả --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['full_name']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Hành động</label>
                        <select name="action" class="form-select">
                            <option value="">-- Tất cả --</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo $filterAction == $action['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filterDate); ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i>Lọc
                            </button>
                            <a href="<?php echo APP_URL; ?>/views/admin/logs.php" class="btn btn-secondary">
                                <i class="fas fa-redo me-1"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Logs Table -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <strong>Tổng cộng:</strong> <?php echo number_format($totalLogs); ?> bản ghi
                    </div>
                    <div>
                        Trang <?php echo $page; ?> / <?php echo max(1, $totalPages); ?>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Thời gian</th>
                                <th>Người dùng</th>
                                <th>Hành động</th>
                                <th>Loại</th>
                                <th>Đối tượng</th>
                                <th>Chi tiết</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                        Không có bản ghi nào
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['id']; ?></td>
                                        <td>
                                            <small><?php echo Helper::formatDate($log['created_at'], DATETIME_FORMAT); ?></small><br>
                                            <small class="text-muted"><?php echo Helper::timeAgo($log['created_at']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($log['username']): ?>
                                                <strong><?php echo htmlspecialchars($log['username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['full_name']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $actionBadges = [
                                                'login' => 'success',
                                                'logout' => 'secondary',
                                                'login_failed' => 'danger',
                                                'file_upload' => 'primary',
                                                'file_download' => 'info',
                                                'file_delete' => 'danger',
                                                'folder_create' => 'success',
                                                'folder_delete' => 'danger',
                                                'user_created' => 'success',
                                                'user_deleted' => 'danger',
                                                'user_registered' => 'success',
                                            ];
                                            $badgeClass = $actionBadges[$log['action']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['entity_type'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($log['entity_name']): ?>
                                                <small><?php echo htmlspecialchars($log['entity_name']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($log['description']): ?>
                                                <small title="<?php echo htmlspecialchars($log['description']); ?>">
                                                    <?php echo htmlspecialchars(mb_substr($log['description'], 0, 50)) . (mb_strlen($log['description']) > 50 ? '...' : ''); ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&user_id=<?php echo $filterUser; ?>&action=<?php echo urlencode($filterAction); ?>&date=<?php echo $filterDate; ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&user_id=<?php echo $filterUser; ?>&action=<?php echo urlencode($filterAction); ?>&date=<?php echo $filterDate; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&user_id=<?php echo $filterUser; ?>&action=<?php echo urlencode($filterAction); ?>&date=<?php echo $filterDate; ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include APP_ROOT . '/views/includes/footer.php';
?>
