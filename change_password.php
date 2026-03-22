<?php
require_once '../includes/config.php';
requireLogin();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$current_password || !$new_password || !$confirm_password) {
        $error = "Please fill in all fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        // Get current password hash
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($current_password, $user['password'])) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt2  = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt2->bind_param("si", $hashed, $_SESSION['user_id']);
            if ($stmt2->execute()) {
                $success = "Password changed successfully!";
            } else {
                $error = "Failed to change password.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

// Determine back link based on role
$role = $_SESSION['role'];
$backLink = BASE_URL;
if ($role === 'student')  $backLink = BASE_URL . 'student/dashboard.php';
if ($role === 'guard')    $backLink = BASE_URL . 'guard/dashboard.php';
if ($role === 'guidance') $backLink = BASE_URL . 'guidance/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Change Password</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>Change Password</h2>
        <p>Update your login password.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <div class="card" style="max-width:500px;">
        <div class="card-title">🔒 Update Password</div>
        <form method="POST">
            <div class="form-group">
                <label>Current Password *</label>
                <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
            </div>
            <div class="form-group">
                <label>New Password *</label>
                <input type="password" name="new_password" class="form-control" placeholder="Min. 6 characters" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password *</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required>
            </div>
            <div style="display:flex; gap:8px; margin-top:0.5rem;">
                <button type="submit" class="btn btn-primary">Change Password</button>
                <a href="<?= $backLink ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>