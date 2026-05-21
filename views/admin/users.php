<?php
/**
 * Admin - User Management
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

// Get all users with roles
$db->query("
    SELECT u.*, r.role_name, r.is_admin,
           (SELECT COUNT(*) FROM files_cokhi WHERE uploaded_by = u.id) as file_count
    FROM users_cokhi u
    LEFT JOIN roles_cokhi r ON u.role_id = r.id
    ORDER BY u.created_at DESC
");
$users = $db->fetchAll();

// Get all roles for dropdown
$db->query("SELECT * FROM roles_cokhi ORDER BY role_name");
$roles = $db->fetchAll();

$title = 'Quản lý người dùng';
$showNav = true;
$showFooter = true;

include APP_ROOT . '/views/includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <h2><i class="fas fa-users me-2"></i>Quản lý người dùng</h2>
    </div>
</div>

<!-- Action Buttons -->
<div class="row mb-3">
    <div class="col-md-12">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="fas fa-user-plus me-2"></i>Thêm người dùng mới
        </button>
    </div>
</div>

<!-- Users Table -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tên đăng nhập</th>
                                <th>Họ tên</th>
                                <th>Email</th>
                                <th>Vai trò</th>
                                <th>Trạng thái</th>
                                <th>Files</th>
                                <th>Ngày tạo</th>
                                <th>Đăng nhập cuối</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                        <?php if ($user['is_admin']): ?>
                                            <span class="badge bg-danger ms-1">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($user['role_name']); ?></span></td>
                                    <td>
                                        <?php
                                        $statusBadge = [
                                            'active' => '<span class="badge bg-success">Active</span>',
                                            'inactive' => '<span class="badge bg-secondary">Inactive</span>',
                                            'suspended' => '<span class="badge bg-danger">Suspended</span>'
                                        ];
                                        echo $statusBadge[$user['status']] ?? $user['status'];
                                        ?>
                                    </td>
                                    <td><?php echo number_format($user['file_count']); ?></td>
                                    <td><?php echo Helper::formatDate($user['created_at']); ?></td>
                                    <td><?php echo $user['last_login'] ? Helper::timeAgo($user['last_login']) : '-'; ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-info" 
                                                    onclick="managePermissions(<?php echo $user['id']; ?>)" title="Phân quyền">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php if ($user['id'] != 1 && $user['id'] != $_SESSION['user_id']): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteUser(<?php echo $user['id']; ?>)" title="Xóa">
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

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Thêm người dùng mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createUserForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Tên đăng nhập *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Họ tên *</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Mật khẩu *</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role_id" class="form-label">Vai trò *</label>
                        <select class="form-select" id="role_id" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo người dùng</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJS = '
<script>
$("#createUserForm").on("submit", function(e) {
    e.preventDefault();
    
    var formData = $(this).serialize();
    var submitBtn = $(this).find("button[type=submit]");
    var originalBtnText = submitBtn.html();
    
    console.log("Form data:", formData);
    
    // Disable submit button
    submitBtn.prop("disabled", true).html("<i class=\"fas fa-spinner fa-spin me-2\"></i>Đang tạo...");
    
    $.ajax({
        url: "' . APP_URL . '/api/user-create.php",
        type: "POST",
        data: formData,
        dataType: "json",
        success: function(response) {
            console.log("Response:", response);
            if (response.success) {
                alert("✓ " + response.message);
                $("#createUserModal").modal("hide");
                location.reload();
            } else {
                alert("✗ Lỗi: " + response.message);
                submitBtn.prop("disabled", false).html(originalBtnText);
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", status, error);
            console.error("Response Text:", xhr.responseText);
            console.error("Status Code:", xhr.status);
            
            var errorMsg = "Lỗi kết nối server";
            try {
                var jsonResponse = JSON.parse(xhr.responseText);
                if (jsonResponse.message) {
                    errorMsg = jsonResponse.message;
                }
            } catch(e) {}
            
            alert("✗ " + errorMsg + "\\n\\nCheck Console (F12) for details");
            submitBtn.prop("disabled", false).html(originalBtnText);
        }
    });
});

// Reset form when modal is closed
$("#createUserModal").on("hidden.bs.modal", function() {
    $("#createUserForm")[0].reset();
});

function managePermissions(userId) {
    window.location.href = "' . APP_URL . '/views/admin/user-permissions.php?id=" + userId;
}

function deleteUser(userId) {
    if (!confirm("Bạn có chắc muốn xóa người dùng này?")) {
        return;
    }
    
    $.post("' . APP_URL . '/api/user-delete.php", {
        user_id: userId
    }, function(response) {
        if (response.success) {
            alert("✓ " + response.message);
            location.reload();
        } else {
            alert("✗ Lỗi: " + response.message);
        }
    }, "json").fail(function() {
        alert("✗ Lỗi kết nối server");
    });
}
</script>
';

include APP_ROOT . '/views/includes/footer.php';
?>
