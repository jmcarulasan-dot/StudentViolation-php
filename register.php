<?php
require_once 'includes/config.php';

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
    $student_no = strtoupper(trim($_POST['student_no'] ?? ''));
    $name       = trim($_POST['name'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 0);
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    // Validate student number format: C26-01-0001-MAN121
    $pattern = '/^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/';

    if (!$student_no || !$name || !$course || !$year_level || !$username || !$password || !$confirm) {
        $error = "Please fill in all fields.";
    } elseif (!preg_match($pattern, $student_no)) {
        $error = "Invalid Student No. format. It must follow: C26-01-0001-MAN121";
    } elseif (strlen($name) < 2) {
        $error = "Please enter a valid full name.";
    } elseif (!in_array($course, ['BSIT','BSCS','BSA','BSBA','BSHM'])) {
        $error = "Invalid course selected.";
    } elseif ($year_level < 1 || $year_level > 4) {
        $error = "Invalid year level.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
        $error = "Username can only contain letters, numbers, dots, and underscores.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM students WHERE student_no = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Student No. is already registered.";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "Username is already taken.";
            } else {
                $stmt = $conn->prepare("INSERT INTO students (student_no, name, course, year_level) VALUES (?,?,?,?)");
                $stmt->bind_param("sssi", $student_no, $name, $course, $year_level);
                if ($stmt->execute()) {
                    $new_student_id = $conn->insert_id;
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
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
        body, html { height: 100%; }

        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow: hidden;
        }

        .register-bg {
            position: fixed;
            inset: 0;
            background: url('<?= BASE_URL ?>assets/image/BackGround.webp') center center / cover no-repeat;
            filter: blur(3px) brightness(0.55);
            transform: scale(1.05);
            z-index: 0;
        }

        .register-box {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 24px 80px rgba(0,0,0,0.35);
            position: relative;
            z-index: 1;
        }

        .register-logo {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .register-logo .logo-circle {
            width: 75px; height: 75px;
            background: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.8rem;
            box-shadow: 0 4px 20px rgba(26,58,92,0.2);
            border: 3px solid #dce3ed;
            overflow: hidden;
        }

        .register-logo .logo-circle img {
            width: 58px; height: 58px;
            object-fit: contain;
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

        /* Student No hint */
        .field-hint {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 4px;
        }

        .field-hint.error { color: #991b1b; }
        .field-hint.ok    { color: #065f46; }
    </style>
</head>
<body>
<div class="register-page">
    <div class="register-bg"></div>
    <div class="register-box">
        <div class="register-logo">
            <div class="logo-circle">
                <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC Logo">
            </div>
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

        <form method="POST" id="regForm">
            <div class="form-row">
                <div class="form-group">
                    <label>Student No. <span style="color:red">*</span></label>
                    <input type="text" name="student_no" id="student_no" class="form-control"
                        placeholder="C26-01-0001-MAN121"
                        value="<?= htmlspecialchars($_POST['student_no'] ?? '') ?>"
                        maxlength="18"
                        oninput="validateStudentNo(this)"
                        required>
                    <div class="field-hint" id="sno-hint">Format: C26-01-0001-MAN121</div>
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
                        foreach (['BSIT','BSCS','BSA','BSBA','BSHM'] as $c) {
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
                    placeholder="Min. 3 characters, letters/numbers/dots/underscores"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password <span style="color:red">*</span></label>
                    <input type="password" name="password" id="pw1" class="form-control"
                        placeholder="Min. 6 characters" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password <span style="color:red">*</span></label>
                    <input type="password" name="confirm_password" id="pw2" class="form-control"
                        placeholder="Re-enter password"
                        oninput="checkPwMatch()" required>
                    <div class="field-hint" id="pw-hint"></div>
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

<script>
function validateStudentNo(input) {
    const val  = input.value.toUpperCase();
    input.value = val;
    const hint = document.getElementById('sno-hint');
    const pattern = /^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/;

    if (val.length === 0) {
        hint.textContent = 'Format: C26-01-0001-MAN121';
        hint.className   = 'field-hint';
    } else if (pattern.test(val)) {
        hint.textContent = '✅ Valid format';
        hint.className   = 'field-hint ok';
    } else {
        hint.textContent = '❌ Must follow: C26-01-0001-MAN121';
        hint.className   = 'field-hint error';
    }
}

function checkPwMatch() {
    const pw1  = document.getElementById('pw1').value;
    const pw2  = document.getElementById('pw2').value;
    const hint = document.getElementById('pw-hint');
    if (!pw2) { hint.textContent = ''; return; }
    if (pw1 === pw2) {
        hint.textContent = '✅ Passwords match';
        hint.className   = 'field-hint ok';
    } else {
        hint.textContent = '❌ Passwords do not match';
        hint.className   = 'field-hint error';
    }
}
</script>
</body>
</html>