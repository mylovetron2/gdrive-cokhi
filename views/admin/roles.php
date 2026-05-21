<?php
/**
 * Admin - Role Management
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_role'])) {
        // Create new role
        $roleName = Helper::sanitize($_POST['role_name'] ?? '');
        $description = Helper::sanitize($_POST['description'] ?? '');
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        
        if (empty($roleName)) {
            $error = "Tên vai trò không được để trống";
        } else {
            try {
                $result = $db->insert('roles_cokhi', [
                    'role_name' => $roleName,
                    'description' => $description,
                    'is_admin' => $isAdmin
                ]);
                
                if ($result) {
                    $success = "Tạo vai trò thành công!";
                } else {
                    $error = "Không thể tạo vai trò";
                }
            } catch (Exception $e) {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['update_role'])) {
        // Update role
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleName = Helper::sanitize($_POST['role_name'] ?? '');
        $description = Helper::sanitize($_POST['description'] ?? '');
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        
        if ($roleId <= 0) {
            $error = "ID vai trò không hợp lệ";
        } elseif (empty($roleName)) {
            $error = "Tên vai trò không được để trống";
        } else {
            try {
                $result = $db->update('roles_cokhi', [
                    'role_name' => $roleName,
                    'description' => $description,
                    'is_admin' => $isAdmin
                ], ['id' => $roleId]);
                
                if ($result) {
                    $success = "Cập nhật vai trò thành công!";
                } else {
                    $error = "Không thể cập nhật vai trò";
                }
            } catch (Exception $e) {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_role'])) {
        // Delete role
        $roleId = (int)($_POST['role_id'] ?? 0);
        
        if ($roleId <= 0) {
            $error = "ID vai trò không hợp lệ";
        } elseif ($roleId == 1) {
            $error = "Không thể xóa vai trò Super Admin";
        } else {
            // Check if any users have this role
            $db->query("SELECT COUNT(*) as count FROM users_cokhi WHERE role_id = :role_id");
            $db->bind(':role_id', $roleId);
            $userCount = $db->fetch();
            
            if ($userCount['count'] > 0) {
                $error = "Không thể xóa vai trò vì có " . $userCount['count'] . " người dùng đang sử dụng";
            } else {
                try {
                    // Delete role permissions first
                    $db->delete('role_permissions_cokhi', ['role_id' => $roleId]);
                    
                    // Delete role
                    $result = $db->delete('roles_cokhi', ['id' => $roleId]);
                    
                    if ($result) {
                        $success = "Xóa vai trò thành công!";
                    } else {
                        $error = "Không thể xóa vai trò";
                    }
                } catch (Exception $e) {
                    $error = "Lỗi: " . $e->getMessage();
                }
            }
        }
    }
}

// Get all roles with statistics
$db->query("
    SELECT r.*,
           (SELECT COUNT(*) FROM users_cokhi WHERE role_id = r.id) as user_count,
           (SELECT COUNT(*) FROM role_permissions_cokhi WHERE role_id = r.id) as permission_count
    FROM roles_cokhi r
    ORDER BY r.id ASC
");
$roles = $db->fetchAll();

// Get total permissions count
$db->query("SELECT COUNT(*) as total FROM permissions_cokhi");
$totalPerms = $db->fetch();

$title = 'Quản lý vai trò';
$showNav = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2><i class="fas fa-user-tag me-2"></i>Quản lý vai trò</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                <i class="fas fa-plus me-2"></i>Thêm vai trò mới
            </button>
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

<!-- Roles Table -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tên vai trò</th>
                                <th>Mô tả</th>
                                <th>Admin</th>
                                <th>Người dùng</th>
                                <th>Quyền</th>
                                <th>Ngày tạo</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                                <tr>
                                    <td><?php echo $role['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($role['role_name']); ?></strong>
                                        <?php if ($role['is_admin']): ?>
                                            <span class="badge bg-danger ms-1">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $desc = htmlspecialchars($role['description'] ?? '');
                                        echo $desc ? $desc : '<span class="text-muted">-</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($role['is_admin']): ?>
                                            <span class="badge bg-success">Có</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Không</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo number_format($role['user_count']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo $role['permission_count']; ?> / <?php echo $totalPerms['total']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo Helper::formatDate($role['created_at']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    onclick="editRole(<?php echo htmlspecialchars(json_encode($role), ENT_QUOTES); ?>)" 
                                                    title="Sửa">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="<?php echo APP_URL; ?>/views/admin/permissions.php?role_id=<?php echo $role['id']; ?>" 
                                               class="btn btn-outline-info" title="Phân quyền">
                                                <i class="fas fa-key"></i>
                                            </a>
                                            <?php if ($role['id'] != 1 && $role['user_count'] == 0): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['role_name']); ?>')" 
                                                        title="Xóa">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
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

<!-- Create Role Modal -->
<div class="modal fade" id="createRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Thêm vai trò mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="role_name" class="form-label">Tên vai trò *</label>
                        <input type="text" class="form-control" id="role_name" name="role_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Mô tả</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin">
                            <label class="form-check-label" for="is_admin">
                                <strong>Vai trò quản trị</strong>
                                <br><small class="text-muted">Vai trò quản trị có toàn quyền truy cập hệ thống</small>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="create_role" class="btn btn-primary">Tạo vai trò</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Chỉnh sửa vai trò</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="">
                <input type="hidden" id="edit_role_id" name="role_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_role_name" class="form-label">Tên vai trò *</label>
                        <input type="text" class="form-control" id="edit_role_name" name="role_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Mô tả</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_admin" name="is_admin">
                            <label class="form-check-label" for="edit_is_admin">
                                <strong>Vai trò quản trị</strong>
                                <br><small class="text-muted">Vai trò quản trị có toàn quyền truy cập hệ thống</small>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="update_role" class="btn btn-primary">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Role Form (hidden) -->
<form id="deleteRoleForm" method="post" action="" style="display: none;">
    <input type="hidden" id="delete_role_id" name="role_id">
    <input type="hidden" name="delete_role" value="1">
</form>

<?php
$extraJS = '
<script>
function editRole(role) {
    $("#edit_role_id").val(role.id);
    $("#edit_role_name").val(role.role_name);
    $("#edit_description").val(role.description || "");
    $("#edit_is_admin").prop("checked", role.is_admin == 1);
    $("#editRoleModal").modal("show");
}

function deleteRole(roleId, roleName) {
    if (!confirm("Bạn có chắc muốn xóa vai trò \"" + roleName + "\"?\\n\\nLưu ý: Chỉ có thể xóa vai trò không có người dùng nào.")) {
        return;
    }
    
    $("#delete_role_id").val(roleId);
    $("#deleteRoleForm").submit();
}

// Reset create form when modal closes
$("#createRoleModal").on("hidden.bs.modal", function() {
    $(this).find("form")[0].reset();
});
</script>
';

include APP_ROOT . '/views/includes/footer.php';
?>
