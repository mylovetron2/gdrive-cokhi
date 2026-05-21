<?php
/**
 * Admin - Role Permissions Management
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

// Get selected role
$selectedRoleId = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;

// Get all roles
$db->query("SELECT * FROM roles_cokhi ORDER BY role_name");
$roles = $db->fetchAll();

// If no role selected, use first role
if ($selectedRoleId <= 0 && !empty($roles)) {
    $selectedRoleId = $roles[0]['id'];
}

// Get selected role info
$selectedRole = null;
if ($selectedRoleId > 0) {
    $db->query("SELECT * FROM roles_cokhi WHERE id = :id");
    $db->bind(':id', $selectedRoleId);
    $selectedRole = $db->fetch();
}

// Get all permissions
$db->query("SELECT * FROM permissions_cokhi ORDER BY permission_name");
$allPermissions = $db->fetchAll();

// Get role's current permissions
$rolePermissions = [];
if ($selectedRoleId > 0) {
    $db->query("
        SELECT permission_id 
        FROM role_permissions_cokhi 
        WHERE role_id = :role_id
    ");
    $db->bind(':role_id', $selectedRoleId);
    $rolePermissionsResult = $db->fetchAll();
    $rolePermissions = array_column($rolePermissionsResult, 'permission_id');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permissions'])) {
    $roleId = (int)($_POST['role_id'] ?? 0);
    $selectedPermissions = isset($_POST['permissions_cokhi']) ? $_POST['permissions_cokhi'] : [];
    
    if ($roleId > 0) {
        try {
            // Delete all existing permissions for this role
            $db->delete('role_permissions_cokhi', ['role_id' => $roleId]);
            
            // Insert new permissions
            foreach ($selectedPermissions as $permissionId) {
                $db->insert('role_permissions_cokhi', [
                    'role_id' => $roleId,
                    'permission_id' => (int)$permissionId
                ]);
            }
            
            $success = "Cập nhật quyền cho vai trò thành công!";
            
            // Refresh role permissions
            $db->query("
                SELECT permission_id 
                FROM role_permissions_cokhi 
                WHERE role_id = :role_id
            ");
            $db->bind(':role_id', $roleId);
            $rolePermissionsResult = $db->fetchAll();
            $rolePermissions = array_column($rolePermissionsResult, 'permission_id');
            
        } catch (Exception $e) {
            $error = "Lỗi khi cập nhật quyền: " . $e->getMessage();
        }
    }
}

$title = 'Quản lý phân quyền vai trò';
$showNav = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="fas fa-shield-alt me-2"></i>Quản lý phân quyền vai trò</h2>
    </div>
</div>

<!-- Role Selector -->
<div class="row mb-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <label class="form-label"><strong>Chọn vai trò:</strong></label>
                        <select class="form-select form-select-lg" id="roleSelector" onchange="changeRole(this.value)">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo $selectedRoleId == $role['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                    <?php if ($role['is_admin']): ?>
                                        <span class="text-danger">(Admin)</span>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <?php if ($selectedRole): ?>
                            <div class="alert alert-info mb-0">
                                <strong>Mô tả:</strong> 
                                <?php echo htmlspecialchars($selectedRole['description'] ?? 'Không có mô tả'); ?>
                                <?php if ($selectedRole['is_admin']): ?>
                                    <br><span class="badge bg-danger mt-1">Vai trò quản trị - có toàn quyền</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
<?php if ($selectedRole): ?>
    <form method="post" action="">
        <input type="hidden" name="role_id" value="<?php echo $selectedRoleId; ?>">
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-key me-2"></i>
                            Quyền cho vai trò: <strong><?php echo htmlspecialchars($selectedRole['role_name']); ?></strong>
                        </h5>
                        <span class="badge bg-primary">
                            <?php echo count($rolePermissions); ?> / <?php echo count($allPermissions); ?> quyền
                        </span>
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
                            
                            $categoryIcons = [
                                'file' => 'fa-file-alt',
                                'folder' => 'fa-folder-open',
                                'user' => 'fa-users',
                                'admin' => 'fa-cog',
                                'other' => 'fa-ellipsis-h'
                            ];
                            
                            foreach ($permissionGroups as $category => $perms):
                            ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="permission-group">
                                        <h6 class="text-primary mb-3 pb-2 border-bottom">
                                            <i class="fas <?php echo $categoryIcons[$category] ?? 'fa-key'; ?> me-2"></i>
                                            <?php echo $categoryNames[$category] ?? ucfirst($category); ?>
                                        </h6>
                                        <div class="ps-2">
                                            <?php foreach ($perms as $perm): ?>
                                                <div class="form-check mb-2">
                                                    <input 
                                                        class="form-check-input" 
                                                        type="checkbox" 
                                                        name="permissions[]" 
                                                        value="<?php echo $perm['id']; ?>" 
                                                        id="perm_<?php echo $perm['id']; ?>"
                                                        <?php echo in_array($perm['id'], $rolePermissions) ? 'checked' : ''; ?>
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
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                                    <i class="fas fa-check-square me-1"></i>Chọn tất cả
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                    <i class="fas fa-square me-1"></i>Bỏ chọn tất cả
                                </button>
                            </div>
                            <div>
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
<?php else: ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Vui lòng chọn vai trò để quản lý quyền.
    </div>
<?php endif; ?>

<!-- Permission Statistics -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Thống kê quyền theo vai trò</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Vai trò</th>
                                <th>Mô tả</th>
                                <th>Số quyền</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                                <?php
                                // Get permission count for this role
                                $db->query("SELECT COUNT(*) as count FROM role_permissions_cokhi WHERE role_id = :role_id");
                                $db->bind(':role_id', $role['id']);
                                $permCount = $db->fetch();
                                ?>
                                <tr class="<?php echo $selectedRoleId == $role['id'] ? 'table-active' : ''; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($role['role_name']); ?></strong>
                                        <?php if ($role['is_admin']): ?>
                                            <span class="badge bg-danger ms-1">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($role['description'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo $permCount['count']; ?> / <?php echo count($allPermissions); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($permCount['count'] == count($allPermissions)): ?>
                                            <span class="badge bg-success">Toàn quyền</span>
                                        <?php elseif ($permCount['count'] > 0): ?>
                                            <span class="badge bg-warning">Giới hạn</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Chưa cấp</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?role_id=<?php echo $role['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i> Chỉnh sửa
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = '
<script>
function selectAll() {
    $("input[type=checkbox][name=\"permissions[]\"]").prop("checked", true);
}

function deselectAll() {
    $("input[type=checkbox][name=\"permissions[]\"]").prop("checked", false);
}

function changeRole(roleId) {
    window.location.href = "?role_id=" + roleId;
}
</script>
';

include APP_ROOT . '/views/includes/footer.php';
?>
