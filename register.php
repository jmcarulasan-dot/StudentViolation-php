<?php
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student')  header("Location: " . BASE_URL . "student/dashboard.php");
    if ($role === 'guard')    header("Location: " . BASE_URL . "guard/dashboard.php");
    if ($role === 'guidance') header("Location: " . BASE_URL . "guidance/dashboard.php");
    exit();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no = trim($_POST['student_no'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 0);
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    if (!$student_no || !$name || !$course || !$year_level || !$username || !$password || !$confirm) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check if student_no already exists
        $stmt = $conn->prepare("SELECT id FROM students WHERE student_no = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Student No. is already registered.";
        } else {
            // Check if username already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username is already taken.";
            } else {
                // Insert student
                $stmt = $conn->prepare("INSERT INTO students (student_no, name, course, year_level) VALUES (?,?,?,?)");
                $stmt->bind_param("sssi", $student_no, $name, $course, $year_level);
                if ($stmt->execute()) {
                    $new_student_id = $conn->insert_id;
                    $hashed = password_hash($password, PASSWORD_DEFAULT);

                    // Insert user account
                    $stmt2 = $conn->prepare("INSERT INTO users (name, username, password, role, student_id) VALUES (?,?,?,'student',?)");
                    $stmt2->bind_param("sssi", $name, $username, $hashed, $new_student_id);
                    if ($stmt2->execute()) {
                        $success = "Registration successful! You can now log in.";
                    } else {
                        $error = "Failed to create account. Try again.";
                    }
                } else {
                    $error = "Failed to register. Student No. may already exist.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Student Registration</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary) 0%, #0f2340 100%);
            padding: 2rem 1rem;
            position: relative;
            overflow: hidden;
        }
        .register-page::before {
            content: '';
            position: absolute;
            width: 500px; height: 500px;
            background: rgba(232,69,69,0.08);
            border-radius: 50%;
            top: -100px; right: -100px;
        }
        .register-box {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 24px 80px rgba(0,0,0,0.3);
            position: relative;
            z-index: 1;
        }
        .register-logo {
            text-align: center;
            margin-bottom: 1.8rem;
        }
        .register-logo .logo-icon {
            width: 56px; height: 56px;
            background: var(--primary);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.8rem;
            font-size: 1.6rem;
        }
        .register-logo h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--primary);
        }
        .register-logo p {
            color: var(--muted);
            font-size: 0.85rem;
            margin-top: 4px;
        }
        .divider {
            text-align: center;
            margin: 1.2rem 0;
            color: var(--muted);
            font-size: 0.85rem;
            position: relative;
        }
        .divider::before, .divider::after {
            content: '';
            position: absolute;
            top: 50%; width: 42%;
            height: 1px;
            background: var(--border);
        }
        .divider::before { left: 0; }
        .divider::after  { right: 0; }
    </style>
</head>
<body>
<div class="register-page">
    <div class="register-box">
        <div class="register-logo">
            <div class="logo-icon">🎓</div>
            <h1>Student Registration</h1>
            <p>ACLC College of Mandaue — SVS</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <a href="<?= BASE_URL ?>login.php" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px;">
                Go to Login →
            </a>
        <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Student No. <span style="color:red">*</span></label>
                    <input type="text" name="student_no" class="form-control"
                        placeholder="C26-01-0001-MAN121"
                        value="<?= htmlspecialchars($_POST['student_no'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Full Name <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control"
                        placeholder="Juan dela Cruz"
                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Course <span style="color:red">*</span></label>
                    <select name="course" class="form-control" required>
                        <option value="">-- Select Course --</option>
                        <?php
                        $courses = ['BSIT','BSCS','BSA','BSBA','BSHM'];
                        foreach ($courses as $c) {
                            $sel = (($_POST['course'] ?? '') === $c) ? 'selected' : '';
                            echo "<option value=\"$c\" $sel>$c</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Year Level <span style="color:red">*</span></label>
                    <select name="year_level" class="form-control" required>
                        <option value="">-- Select Year --</option>
                        <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?= $y ?>" <?= (($_POST['year_level'] ?? '') == $y) ? 'selected' : '' ?>>Year <?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="divider">Login Credentials</div>

            <div class="form-group">
                <label>Username <span style="color:red">*</span></label>
                <input type="text" name="username" class="form-control"
                    placeholder="Choose a username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password <span style="color:red">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password <span style="color:red">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px; margin-top:4px;">
                Register →
            </button>
        </form>

        <?php endif; ?>

        <p style="text-align:center; margin-top:1.2rem; font-size:0.85rem; color:var(--muted);">
            Already have an account? <a href="<?= BASE_URL ?>login.php" style="color:var(--primary); font-weight:600;">Login here</a>
        </p>
    </div>
</div>
</body>
</html>