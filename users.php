<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Enforce admin privileges before rendering
requireAdmin();

$pageTitle = 'User Administration';
$currentPage = 'users';
require_once __DIR__ . '/includes/header.php';
$pdo = getDB();

$msg = '';
$err = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'user_deleted') {
    $msg = "User account has been permanently deleted.";
}

/**
 * Helper to generate a strict, clean 'first.lastname' username from Full Name
 */
function generateUsername(string $fullName, PDO $pdo, int $excludeId = 0): string {
    $parts = array_values(array_filter(preg_split('/\s+/', strtolower(trim($fullName)))));
    
    if (count($parts) >= 2) {
        $first = preg_replace('/[^a-z0-9]/', '', $parts[0]);
        $last = preg_replace('/[^a-z0-9]/', '', end($parts));
        $base = "{$first}.{$last}";
    } elseif (count($parts) === 1) {
        $base = preg_replace('/[^a-z0-9]/', '', $parts[0]);
    } else {
        $base = 'operator';
    }

    if (empty($base)) {
        $base = 'operator';
    }

    // Ensure uniqueness across the database
    $username = $base;
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $excludeId]);
        if (!$stmt->fetch()) {
            break;
        }
        $counter++;
        $username = "{$base}{$counter}";
    }

    return $username;
}

// Handle Create User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'pilot';

    if ($fullName && $password && $email) {
        $username = generateUsername($fullName, $pdo);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $fullName, $email, $phone, $role]);
            $msg = "User account created successfully for {$fullName} (Username: <code>{$username}</code>).";
        } catch (PDOException $e) {
            $err = 'Error creating user: ' . $e->getMessage();
        }
    } else {
        $err = 'Please fill out all required fields.';
    }
}

// Handle Update User via Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'pilot';

    if ($userId && $fullName && $email) {
        $username = generateUsername($fullName, $pdo, $userId);

        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, full_name = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $hash, $fullName, $email, $phone, $role, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                $stmt->execute([$username, $fullName, $email, $phone, $role, $userId]);
            }
            $msg = "User account updated successfully for {$fullName} (Username: <code>{$username}</code>).";
        } catch (PDOException $e) {
            $err = 'Error updating user: ' . $e->getMessage();
        }
    } else {
        $err = 'Required fields cannot be empty.';
    }
}

// Handle Status Toggle (Enable / Disable)
if (isset($_GET['toggle_id'])) {
    $toggleId = (int)$_GET['toggle_id'];
    if ($toggleId !== (int)$_SESSION['user_id']) {
        $pdo->prepare("UPDATE users SET is_active = (1 - is_active) WHERE id = ?")->execute([$toggleId]);
        header('Location: users.php');
        exit;
    }
}

// Handle Delete User
if (isset($_GET['delete_id'])) {
    $deleteId = (int)$_GET['delete_id'];
    if ($deleteId === (int)$_SESSION['user_id']) {
        $err = "You cannot delete your own active administrator account.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$deleteId]);
            header('Location: users.php?msg=user_deleted');
            exit;
        } catch (PDOException $e) {
            $err = "Unable to delete user: this operator is associated with existing flight plans, debrief logs, or system audit records. Disable the account instead to retain flight history.";
        }
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- 1/3 (Left) and 2/3 (Right) Layout -->
<div class="user-admin-split" style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; align-items: start; width: 100%;">
    
    <!-- LEFT COLUMN: ADD NEW USER FORM (1/3 of page) -->
    <div class="user-admin-left" style="min-width: 0;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Add New User</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="users.php">
                    <input type="hidden" name="action" value="create_user">

                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" 
                               name="full_name" 
                               id="full_name" 
                               class="form-control" 
                               placeholder="e.g. Dylan Nichols" 
                               required 
                               autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" 
                               name="password" 
                               id="password" 
                               class="form-control" 
                               required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" 
                               name="email" 
                               id="email" 
                               class="form-control" 
                               placeholder="user@example.com" 
                               required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" 
                               name="phone" 
                               id="phone" 
                               class="form-control" 
                               placeholder="555-0100">
                    </div>

                    <div class="form-group">
                        <label for="role">System Role</label>
                        <select name="role" id="role" class="form-control">
                            <option value="pilot" selected>Remote Pilot (RPIC)</option>
                            <option value="admin">Site Administrator</option>
                        </select>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary btn-block">Create User Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: REGISTERED OPERATORS TABLE (2/3 of page) -->
    <div class="user-admin-right" style="min-width: 0;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Registered Operators &amp; Personnel</h2>
                <span class="badge badge-neutral"><?= count($users) ?> Accounts</span>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="overflow-x: auto; width: 100%;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Operator Name</th>
                                <th>Assigned Username</th>
                                <th>Role</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                                    <td>
                                        <span class="badge <?= $u['role'] === 'admin' ? 'badge-info' : 'badge-neutral' ?>">
                                            <?= strtoupper($u['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($u['email']) ?></small><br>
                                        <small style="color:var(--text-muted);"><?= htmlspecialchars($u['phone'] ?: 'No phone') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $u['is_active'] ? 'ACTIVE' : 'DISABLED' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                                            <button type="button" 
                                                    class="btn btn-outline btn-sm btn-edit-user"
                                                    data-id="<?= $u['id'] ?>"
                                                    data-fullname="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                    data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?>"
                                                    data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>">
                                                Edit
                                            </button>
                                            <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                                <a href="users.php?toggle_id=<?= $u['id'] ?>" class="btn btn-outline btn-sm">
                                                    <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                                                </a>
                                                <a href="users.php?delete_id=<?= $u['id'] ?>" 
                                                   class="btn btn-outline btn-sm" 
                                                   style="color: var(--danger); border-color: var(--border);"
                                                   onclick="return confirm('Are you sure you want to permanently delete <?= htmlspecialchars(addslashes($u['full_name'])) ?>? This cannot be undone.');">
                                                    Delete
                                                </a>
                                            <?php else: ?>
                                                <small style="color:var(--text-muted); padding: 0 0.3rem;">(You)</small>
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

<!-- POP-UP MODAL: EDIT USER DETAILS -->
<div id="edit-user-modal" class="modal-backdrop" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Edit User Information</h3>
            <button type="button" class="modal-close-btn" id="modal-close-x">&times;</button>
        </div>
        <form method="POST" action="users.php">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div class="form-group">
                    <label for="edit_full_name">Full Name</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_role">System Role</label>
                    <select name="role" id="edit_role" class="form-control">
                        <option value="pilot">Remote Pilot (RPIC)</option>
                        <option value="admin">Site Administrator</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_password">Password <span style="font-weight:normal; color:var(--text-muted);">(Leave blank to keep current)</span></label>
                    <input type="password" name="password" id="edit_password" class="form-control" placeholder="••••••••">
                </div>

                <div class="grid grid-cols-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label for="edit_email">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_phone">Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline btn-sm" id="modal-cancel-btn">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
