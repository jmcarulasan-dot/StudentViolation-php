<?php
require_once '../includes/config.php';
requireLogin('student');

$student_id = $_SESSION['student_id'];
$success    = '';
$error      = '';
$pw_success = '';
$pw_error   = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name       = trim($_POST['name'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 0);

    if ($name && $course && $year_level) {
        $stmt = $conn->prepare("UPDATE students SET name = ?, course = ?, year_level = ? WHERE id = ?");
        $stmt->bind_param("ssii", $name, $course, $year_level, $student_id);
        if ($stmt->execute()) {
            $stmt2 = $conn->prepare("UPDATE users SET name = ? WHERE student_id = ?");
            $stmt2->bind_param("si", $name, $student_id);
            $stmt2->execute();
            $_SESSION['name'] = $name;
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!password_verify($current, $user['password'])) {
        $pw_error = "Current password is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $pw_error = "New password must be at least 8 characters.";
    } elseif ($new_pw !== $confirm) {
        $pw_error = "New passwords do not match.";
    } else {
        $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE student_id = ?");
        $stmt->bind_param("si", $hashed, $student_id);
        $stmt->execute()
            ? $pw_success = "Password changed successfully!"
            : $pw_error   = "Failed to update password.";
    }
}

// Get student info
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// If password errors/success exist, keep the form open on reload
$keepPwOpen = ($pw_error || $pw_success) ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — My Profile</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>My Profile</h2>
        <p>View and update your personal information.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <div class="card">
        <div class="card-title">👤 Personal Information</div>

        <!-- Profile Info Display -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
                    gap:1rem; margin-bottom:2rem; padding:1rem;
                    background:var(--bg); border-radius:10px;">
            <div>
                <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Student No.</div>
                <div style="font-weight:700; margin-top:4px; color:var(--primary);"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <div>
                <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Full Name</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['name']) ?></div>
            </div>
            <div>
                <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Course</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['course']) ?></div>
            </div>
            <div>
                <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Year Level</div>
                <div style="font-weight:700; margin-top:4px;">Year <?= $student['year_level'] ?></div>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="card-title">✏️ Update Information</div>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($student['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Course *</label>
                    <select name="course" class="form-control" required>
                        <?php foreach (['BSIT','BSCS','BSA','BSBA','BSHM'] as $c): ?>
                            <option value="<?= $c ?>" <?= $student['course'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group" style="max-width:200px;">
                <label>Year Level *</label>
                <select name="year_level" class="form-control" required>
                    <?php for ($y = 1; $y <= 4; $y++): ?>
                        <option value="<?= $y ?>" <?= $student['year_level'] == $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Buttons row -->
            <div style="display:flex; gap:8px; margin-top:.5rem; flex-wrap:wrap; align-items:center;">
                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                <a href="<?= BASE_URL ?>student/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
                <button type="button" class="btn btn-outline" id="togglePwBtn"
                        onclick="togglePassword()"
                        style="margin-left:auto;">
                    🔒 Change Password
                </button>
            </div>
        </form>

        <!-- ── Change Password Section (hidden by default) ── -->
        <div id="pwSection" style="display:none; margin-top:1.5rem;
             border-top:1.5px solid var(--border, #e2e8f0); padding-top:1.5rem;">

            <div class="card-title">🔒 Change Password</div>

            <?php if ($pw_success): ?><div class="alert alert-success"><?= $pw_success ?></div><?php endif; ?>
            <?php if ($pw_error):   ?><div class="alert alert-error"><?= $pw_error ?></div><?php endif; ?>

            <form method="POST">
                <div class="form-group" style="max-width:420px;">
                    <label>Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group" style="max-width:420px;">
                    <label>New Password *
                        <span style="color:var(--muted); font-size:.8rem;">(min. 8 characters)</span>
                    </label>
                    <input type="password" name="new_password" id="new_password" class="form-control" required>
                </div>
                <div class="form-group" style="max-width:420px;">
                    <label>Confirm New Password *</label>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="form-control" oninput="checkMatch()" required>
                    <small id="matchMsg" style="display:none; margin-top:4px;"></small>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
                    <button type="button" class="btn btn-outline" onclick="togglePassword()">Cancel</button>
                </div>
            </form>
        </div>

    </div><!-- end .card -->
</div><!-- end .page-wrapper -->

<script>
// Keep password section open if there were pw errors/success after POST
const keepOpen = <?= $keepPwOpen ?>;
if (keepOpen) showPassword();

function togglePassword() {
    const sec = document.getElementById('pwSection');
    const btn = document.getElementById('togglePwBtn');
    const isHidden = sec.style.display === 'none';
    sec.style.display  = isHidden ? 'block' : 'none';
    btn.textContent    = isHidden ? '✖ Hide Password Form' : '🔒 Change Password';
}

function showPassword() {
    document.getElementById('pwSection').style.display = 'block';
    document.getElementById('togglePwBtn').textContent = '✖ Hide Password Form';
}

function checkMatch() {
    const np  = document.getElementById('new_password').value;
    const cp  = document.getElementById('confirm_password').value;
    const msg = document.getElementById('matchMsg');
    if (!cp) { msg.style.display = 'none'; return; }
    if (np === cp) {
        msg.style.display = 'block';
        msg.style.color   = 'green';
        msg.textContent   = '✅ Passwords match';
    } else {
        msg.style.display = 'block';
        msg.style.color   = 'red';
        msg.textContent   = '❌ Passwords do not match';
    }
}
</script>
</body>
</html>