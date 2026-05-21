<?php
/**
 * Admin - User Permissions Management
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

// Get user ID from query string
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    Helper::redirect(APP_URL . '/views/admin/users.php');
    exit;
}

// Get user info
$db->query("
    SELECT u.*, r.role_name, r.is_admin
    FROM users_cokhi u
    LEFT JOIN roles_cokhi r ON u.role_id = r.id
    WHERE u.id = :id
");
$db->bind(':id', $userId);
$user = $db->fetch();

if (!$user) {
    Helper::redirect(APP_URL . '/views/admin/users.php');
    exit;
}

// Get all permissions
$db->query("SELECT * FROM permissions_cokhi ORDER BY permission_name");
$allPermissions = $db->fetchAll();

// Get user's current permissions
$db->query("
    SELECT permission_id 
    FROM user_permissions_cokhi 
    WHERE user_id = :user_id
");
$db->bind(':user_id', $userId);
$userPermissionsResult = $db->fetchAll();
$userPermissions = array_column($userPermissionsResult, 'permission_id');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permissions'])) {
    $selectedPermissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
    
    try {
        // Delete all existing permissions
        $db->delete('user_permissions_cokhi', ['user_id' => $userId]);
        
        // Insert new permissions
        foreach ($selectedPermissions as $permissionId) {
            $db->insert('user_permissions_cokhi', [
                'user_id' => $userId,
                'permission_id' => (int)$permissionId,
                'granted_by' => $_SESSION['user_id']
            ]);
        }
        
        $success = "Cập nhật quyền thành công!";
        
        // Refresh user permissions
        $db->query("
            SELECT permission_id 
            FROM user_permissions_cokhi 
            WHERE user_id = :user_id
        ");
        $db->bind(':user_id', $userId);
        $userPermissionsResult = $db->fetchAll();
        $userPermissions = array_column($userPermissionsResult, 'permission_id');
        
    } catch (Exception $e) {
        $error = "Lỗi khi cập nhật quyền: " . $e->getMessage();
    }
}

$title = 'Phân quyền người dùng';
$showNav = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2><i class="fas fa-key me-2"></i>Phân quyền người dùng</h2>
            <a href="<?php echo APP_URL; ?>/views/admin/users.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Quay lại
            </a>
        </div>
    </div>
</div>

<!-- User Info -->
<div class="row mb-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="card-title mb-3">Thông tin người dùng</h5>
                        <table class="table table-borderless table-sm">
                            <tr>
                                <th width="150">Tên đăng nhập:</th>
                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Họ tên:</th>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                            </tr>
                            <tr>
                                <th>Vai trò:</th>
                                <td>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($user['role_name']); ?></span>
                                    <?php if ($user['is_admin']): ?>
                                        <span class="badge bg-danger ms-1">Admin</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Lưu ý:</strong> Quyền được gán cho người dùng sẽ ghi đè quyền mặc định của vai trò. 
                            Nếu không gán quyền cụ thể, người dùng sẽ sử dụng quyền của vai trò.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Permissions Form -->
<form method="post" action="">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Danh sách quyền</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        // Group permissions by category
                        $permissionGroups = [];
                        foreach ($allPermissions as $perm) {
                            $parts = explode('.', $perm['permission_name']);
                            $category = isset($parts[0]) ? $parts[0] : 'other';
                            if (!isset($permissionGroups[$category])) {
                                $permissionGroups[$category] = [];
                            }
                            $permissionGroups[$category][] = $perm;
                        }
                        
                        $categoryNames = [
                            'file' => 'Quản lý File',
                            'folder' => 'Quản lý Thư mục',
                            'user' => 'Quản lý Người dùng',
                            'admin' => 'Quản trị hệ thống',
                            'other' => 'Khác'
                        ];
                        
                        foreach ($permissionGroups as $category => $perms):
                        ?>
                            <div class="col-md-6 mb-4">
                                <h6 class="text-primary mb-3">
                                    <i class="fas fa-folder-open me-2"></i>
                                    <?php echo $categoryNames[$category] ?? ucfirst($category); ?>
                                </h6>
                                <div class="ps-3">
                                    <?php foreach ($perms as $perm): ?>
                                        <div class="form-check mb-2">
                                            <input 
                                                class="form-check-input" 
                                                type="checkbox" 
                                                name="permissions[]" 
                                                value="<?php echo $perm['id']; ?>" 
                                                id="perm_<?php echo $perm['id']; ?>"
                                                <?php echo in_array($perm['id'], $userPermissions) ? 'checked' : ''; ?>
                                            >
                                            <label class="form-check-label" for="perm_<?php echo $perm['id']; ?>">
                                                <strong><?php echo htmlspecialchars($perm['permission_name']); ?></strong>
                                                <?php if ($perm['description']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($perm['description']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                                <i class="fas fa-check-square me-1"></i>Chọn tất cả
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                <i class="fas fa-square me-1"></i>Bỏ chọn tất cả
                            </button>
                        </div>
                        <div>
                            <a href="<?php echo APP_URL; ?>/views/admin/users.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Hủy
                            </a>
                            <button type="submit" name="update_permissions" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Lưu thay đổi
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$extraJS = '
<script>
function selectAll() {
    $("input[type=checkbox][name=\"permissions[]\"]").prop("checked", true);
}

function deselectAll() {
    $("input[type=checkbox][name=\"permissions[]\"]").prop("checked", false);
}
</script>
';

include APP_ROOT . '/views/includes/footer.php';
?>
