
<?php
session_start();
require_once '../config/database.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Admin-only access
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Access Denied. Admins only.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($message) {
    header("Location: user_management.php?message=" . urlencode($message));
    exit;
}

$error = "";

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['csrf_token'], $postedToken)
    ) {
        exit("Invalid request token.");
    }

    $action = $_POST['action'] ?? '';

    try {

        // ADD USER
        if ($action === 'add') {

            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'staff';

            if ($full_name === '' || $username === '' || $password === '') {
                throw new Exception("Please complete all required fields.");
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception("Invalid user role.");
            }

            if (strlen($password) < 8) {
                throw new Exception("Password must be at least 8 characters.");
            }

            $check = $conn->prepare("
                SELECT user_id FROM users WHERE username = ?
            ");
            $check->execute([$username]);

            if ($check->fetch()) {
                throw new Exception("Username already exists.");
            }

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (full_name, username, password, role, status)
                VALUES (?, ?, ?, ?, 'active')
            ");

            $stmt->execute([
                $full_name,
                $username,
                $hashedPassword,
                $role
            ]);

            redirectWithMessage("User added successfully.");
        }

        // EDIT USER
        elseif ($action === 'edit') {

            $user_id = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $role = $_POST['role'] ?? '';
            $password = $_POST['password'] ?? '';

            if ($user_id <= 0 || $full_name === '' || $username === '') {
                throw new Exception("Please complete all required fields.");
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception("Invalid user role.");
            }

            $check = $conn->prepare("
                SELECT user_id, role, status
                FROM users
                WHERE user_id = ?
            ");
            $check->execute([$user_id]);

            $target = $check->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception("User not found.");
            }

            // Prevent removing the last active admin
            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $role !== 'admin'
            ) {
                $count = $conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin'
                    AND status = 'active'
                ")->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        "You cannot remove the last active admin."
                    );
                }
            }

            // Check duplicate username
            $checkUsername = $conn->prepare("
                SELECT user_id
                FROM users
                WHERE username = ?
                AND user_id != ?
            ");
            $checkUsername->execute([$username, $user_id]);

            if ($checkUsername->fetch()) {
                throw new Exception(
                    "Username is already used by another account."
                );
            }

            if ($password !== '') {

                if (strlen($password) < 8) {
                    throw new Exception(
                        "New password must be at least 8 characters."
                    );
                }

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    UPDATE users
                    SET full_name = ?,
                        username = ?,
                        role = ?,
                        password = ?
                    WHERE user_id = ?
                ");

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $hashedPassword,
                    $user_id
                ]);

            } else {

                $stmt = $conn->prepare("
                    UPDATE users
                    SET full_name = ?,
                        username = ?,
                        role = ?
                    WHERE user_id = ?
                ");

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $user_id
                ]);
            }

            redirectWithMessage("User updated successfully.");
        }

        // ACTIVATE / DEACTIVATE
        elseif ($action === 'toggle_status') {

            $user_id = (int)($_POST['user_id'] ?? 0);

            if ($user_id <= 0) {
                throw new Exception("Invalid user.");
            }

            // Prevent changing own status
            if ($user_id === (int)$_SESSION['user_id']) {
                throw new Exception(
                    "You cannot deactivate or change your own account status."
                );
            }

            $stmt = $conn->prepare("
                SELECT role, status
                FROM users
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);

            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception("User not found.");
            }

            $newStatus = $target['status'] === 'active'
                ? 'inactive'
                : 'active';

            // Prevent deactivating the last active admin
            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $newStatus === 'inactive'
            ) {
                $count = $conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin'
                    AND status = 'active'
                ")->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        "You cannot deactivate the last active admin."
                    );
                }
            }

            $update = $conn->prepare("
                UPDATE users
                SET status = ?
                WHERE user_id = ?
            ");

            $update->execute([$newStatus, $user_id]);

            redirectWithMessage(
                "Account status updated successfully."
            );
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $e) {

        error_log($e->getMessage());
        $error = "Database error. Please check the information and try again.";

    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}

// Get all users
$stmt = $conn->query("
    SELECT user_id, full_name, username, role, status, created_at
    FROM users
    ORDER BY user_id DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = $_GET['message'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>User Management | Bakery System</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    color: #333;
}

.container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.header h2 {
    margin: 0;
    color: #333;
}

.back-btn {
    background: #555;
    color: white;
    padding: 10px 16px;
    text-decoration: none;
    border-radius: 6px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 10px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.card h3 {
    margin-top: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 15px;
}

button {
    border: none;
    cursor: pointer;
    padding: 9px 13px;
    border-radius: 5px;
    color: white;
}

.add-btn {
    background: #198754;
    margin-top: 18px;
    padding: 11px 20px;
}

.edit-btn {
    background: #0d6efd;
}

.activate-btn {
    background: #198754;
}

.deactivate-btn {
    background: #dc3545;
}

.alert {
    padding: 13px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.success {
    background: #d1e7dd;
    color: #0f5132;
}

.error {
    background: #f8d7da;
    color: #842029;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th, td {
    padding: 13px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

th {
    background: #f1f3f5;
}

.status {
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.status.active {
    background: #d1e7dd;
    color: #0f5132;
}

.status.inactive {
    background: #f8d7da;
    color: #842029;
}

.role {
    text-transform: capitalize;
    font-weight: bold;
}

.actions {
    display: flex;
    gap: 7px;
    align-items: center;
}

.actions form {
    margin: 0;
}

@media(max-width: 650px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .container {
        padding: 12px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>User Management</h2>

        <a href="dashboard.php" class="back-btn">
            ← Back to Dashboard
        </a>
    </div>

    <?php if ($message): ?>
        <div class="alert success">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>


    <!-- ADD NEW USER -->
    <div class="card">

        <h3>Add New User</h3>

        <form method="POST"
              id="addUserForm"
              autocomplete="off">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden"
                   name="action"
                   value="add">

            <!-- Autofill decoy fields -->
            <input type="text"
                   name="dummy_username"
                   autocomplete="username"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute;left:-9999px;">

            <input type="password"
                   name="dummy_password"
                   autocomplete="current-password"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute;left:-9999px;">

            <div class="form-grid">

                <div class="form-group">
                    <label>Full Name</label>

                    <input type="text"
                           name="full_name"
                           placeholder="Enter full name"
                           autocomplete="off"
                           required>
                </div>

                <div class="form-group">
                    <label>Username</label>

                    <input type="text"
                           name="username"
                           placeholder="Enter username"
                           autocomplete="off"
                           readonly
                           onfocus="this.removeAttribute('readonly')"
                           required>
                </div>

                <div class="form-group">
                    <label>Password</label>

                    <input type="password"
                           name="password"
                           minlength="8"
                           placeholder="At least 8 characters"
                           autocomplete="new-password"
                           readonly
                           onfocus="this.removeAttribute('readonly')"
                           required>
                </div>

                <div class="form-group">
                    <label>Role</label>

                    <select name="role" required>
                        <option value="staff" selected>Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

            </div>

            <button type="submit" class="add-btn">
                + Add User
            </button>

        </form>

    </div>


    <!-- REGISTERED USERS -->
    <div class="card">

        <h3>Registered Users</h3>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($users) > 0): ?>

                    <?php foreach ($users as $user): ?>

                    <tr>

                        <td><?= e($user['user_id']) ?></td>

                        <td><?= e($user['full_name']) ?></td>

                        <td><?= e($user['username']) ?></td>

                        <td class="role">
                            <?= e($user['role']) ?>
                        </td>

                        <td>
                            <span class="status <?= e($user['status']) ?>">
                                <?= e(ucfirst($user['status'])) ?>
                            </span>
                        </td>

                        <td>
                            <?= e($user['created_at']) ?>
                        </td>

                        <td>

                            <div class="actions">

                                <button
                                    type="button"
                                    class="edit-btn"
                                    onclick='editUser(
                                        <?= json_encode([
                                            "user_id" => $user["user_id"],
                                            "full_name" => $user["full_name"],
                                            "username" => $user["username"],
                                            "role" => $user["role"]
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                                    )'>
                                    Edit
                                </button>

                                <?php if ((int)$user['user_id'] !== (int)$_SESSION['user_id']): ?>

                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to change this account status?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="toggle_status">

                                        <input type="hidden"
                                               name="user_id"
                                               value="<?= e($user['user_id']) ?>">

                                        <?php if ($user['status'] === 'active'): ?>

                                            <button type="submit"
                                                    class="deactivate-btn">
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button type="submit"
                                                    class="activate-btn">
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7">No users found.</td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- EDIT USER -->
    <div class="card" id="editSection" style="display:none;">

        <h3>Edit User</h3>

        <form method="POST" autocomplete="off">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden"
                   name="action"
                   value="edit">

            <input type="hidden"
                   name="user_id"
                   id="edit_user_id">

            <div class="form-grid">

                <div class="form-group">
                    <label>Full Name</label>

                    <input type="text"
                           name="full_name"
                           id="edit_full_name"
                           required>
                </div>

                <div class="form-group">
                    <label>Username</label>

                    <input type="text"
                           name="username"
                           id="edit_username"
                           autocomplete="off"
                           required>
                </div>

                <div class="form-group">
                    <label>New Password</label>

                    <input type="password"
                           name="password"
                           minlength="8"
                           autocomplete="new-password"
                           placeholder="Leave blank to keep current password">
                </div>

                <div class="form-group">
                    <label>Role</label>

                    <select name="role" id="edit_role" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

            </div>

            <button type="submit" class="edit-btn">
                Save Changes
            </button>

            <button type="button"
                    style="background:#6c757d;"
                    onclick="closeEdit()">
                Cancel
            </button>

        </form>

    </div>

</div>


<script>

// Edit user
function editUser(user) {

    document.getElementById('edit_user_id').value = user.user_id;

    document.getElementById('edit_full_name').value = user.full_name;

    document.getElementById('edit_username').value = user.username;

    document.getElementById('edit_role').value = user.role;

    const section = document.getElementById('editSection');

    section.style.display = 'block';

    section.scrollIntoView({
        behavior: 'smooth'
    });
}

// Close edit form
function closeEdit() {

    document.getElementById('editSection').style.display = 'none';

}

// Clear Add User form after submission
document.getElementById('addUserForm').addEventListener('submit', function() {

    const form = this;

    setTimeout(function() {
        form.reset();
    }, 0);

});

</script>

</body>
</html>
