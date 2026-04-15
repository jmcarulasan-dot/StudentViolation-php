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

    $pattern = '/^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/';

    if (!$student_no || !$name || !$course || !$year_level || !$username || !$password || !$confirm) {
        $error = "Please fill in all fields.";
    } elseif (!preg_match($pattern, $student_no)) {
        $error = "Invalid Student No. format. Must follow: C26-01-0001-MAN121";
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
                    $stmt2->execute()
                        ? $success = "Registration successful! You can now log in."
                        : $error   = "Failed to create account. Try again.";
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
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary:      #1a3a5c;
            --accent:       #e84545;
            --bg:           #f0f4f8;
            --white:        #ffffff;
            --text:         #1e2a3a;
            --muted:        #64748b;
            --border:       #cbd5e1;
            --border-focus: #1a3a5c;
            --input-bg:     #f8fafc;
            --radius:       12px;
            --radius-lg:    20px;
            --success-bg:   #f0fdf4;
            --success-text: #166534;
            --success-border: #bbf7d0;
            --error-bg:     #fef2f2;
            --error-text:   #991b1b;
            --error-border: #fecaca;
        }

        body, html {
            height: 100%;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

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
            filter: blur(4px) brightness(0.45);
            transform: scale(1.08);
            z-index: 0;
        }

        /* ── Card ── */
        .register-box {
            position: relative;
            z-index: 1;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2.5rem 2.5rem 2rem;
            width: 100%;
            max-width: 540px;
            border: 2px solid rgba(255,255,255,0.6);
            box-shadow: 0 24px 80px rgba(0,0,0,0.35), 0 2px 0 rgba(255,255,255,0.5) inset;
        }

        /* ── Header ── */
        .register-logo {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .logo-circle {
            width: 72px; height: 72px;
            background: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.9rem;
            border: 2.5px solid var(--border);
            box-shadow: 0 4px 16px rgba(26,58,92,0.12);
            overflow: hidden;
        }

        .logo-circle img {
            width: 56px; height: 56px;
            object-fit: contain;
        }

        .register-logo h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.3px;
        }

        .register-logo p {
            color: var(--muted);
            font-size: 0.83rem;
            margin-top: 3px;
        }

        /* ── Alert ── */
        .alert {
            padding: 11px 14px;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1.5px solid;
        }
        .alert-error   { background: var(--error-bg);   color: var(--error-text);   border-color: var(--error-border); }
        .alert-success { background: var(--success-bg); color: var(--success-text); border-color: var(--success-border); }
        .alert-icon { width: 18px; height: 18px; flex-shrink: 0; }

        /* ── Section divider ── */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 1.2rem 0 1rem;
        }
        .section-divider::before, .section-divider::after {
            content: '';
            flex: 1;
            height: 1.5px;
            background: var(--border);
            border-radius: 2px;
        }
        .section-divider span {
            font-size: 0.74rem;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        /* ── Grid ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* ── Field ── */
        .field { margin-bottom: 1rem; }

        .field label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .field label .req { color: var(--accent); }

        .field label svg {
            width: 14px; height: 14px;
            color: var(--muted);
            flex-shrink: 0;
        }

        /* ── Inputs ── */
        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 17px; height: 17px;
            color: var(--muted);
            pointer-events: none;
        }

        input.form-control,
        select.form-control {
            width: 100%;
            padding: 11px 14px 11px 42px;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.88rem;
            color: var(--text);
            background: var(--input-bg);
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }

        input.form-control:focus,
        select.form-control:focus {
            border-color: var(--border-focus);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(26,58,92,0.08);
        }

        input.form-control::placeholder { color: #a0aec0; }

        /* Select arrow */
        .select-wrap { position: relative; }
        .select-wrap::after {
            content: '';
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 6px solid var(--muted);
            pointer-events: none;
        }

        select.form-control { padding-right: 36px; cursor: pointer; }

        /* ── Hints ── */
        .field-hint {
            font-size: 0.74rem;
            color: var(--muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .field-hint.ok    { color: #166534; }
        .field-hint.error { color: #991b1b; }

        /* ── Submit ── */
        .submit-btn {
            width: 100%;
            padding: 13px;
            margin-top: 0.4rem;
            background: var(--primary);
            color: white;
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .submit-btn:hover  { background: #14304d; border-color: #14304d; box-shadow: 0 4px 16px rgba(26,58,92,0.25); }
        .submit-btn:active { transform: scale(0.98); }

        /* ── Login link ── */
        .login-link {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.85rem;
            color: var(--muted);
        }
        .login-link a { color: var(--primary); font-weight: 700; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }

        /* ── Success state ── */
        .success-state {
            text-align: center;
            padding: 1rem 0;
        }
        .success-icon {
            width: 56px; height: 56px;
            background: var(--success-bg);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            border: 2px solid var(--success-border);
        }
        .success-icon svg { width: 28px; height: 28px; color: #166534; }
        .success-state h3 { font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 0.4rem; }
        .success-state p  { color: var(--muted); font-size: 0.88rem; margin-bottom: 1.4rem; }

        .go-login-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--primary);
            color: white;
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s;
        }
        .go-login-btn:hover { background: #14304d; }

        @media (max-width: 560px) {
            .form-row { grid-template-columns: 1fr; }
            .register-box { padding: 2rem 1.4rem 1.6rem; }
        }
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
        <div class="success-state">
            <div class="success-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h3>Registration Successful!</h3>
            <p><?= htmlspecialchars($success) ?></p>
            <a href="<?= BASE_URL ?>login.php" class="go-login-btn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Go to Login
            </a>
        </div>

        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="regForm">
            <!-- Student Info -->
            <div class="form-row">
                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        Student No. <span class="req">*</span>
                    </label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        <input type="text" name="student_no" id="student_no" class="form-control"
                            placeholder="C26-01-0001-MAN121"
                            value="<?= htmlspecialchars($_POST['student_no'] ?? '') ?>"
                            maxlength="18"
                            oninput="validateStudentNo(this)"
                            required>
                    </div>
                    <div class="field-hint" id="sno-hint">Format: C26-01-0001-MAN121</div>
                </div>

                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        Full Name <span class="req">*</span>
                    </label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <input type="text" name="name" class="form-control"
                            placeholder="Juan dela Cruz"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                        </svg>
                        Course <span class="req">*</span>
                    </label>
                    <div class="input-wrap select-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                        </svg>
                        <select name="course" class="form-control" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach (['BSIT','BSCS','BSA','BSBA','BSHM'] as $c): ?>
                                <option value="<?= $c ?>" <?= (($_POST['course'] ?? '') === $c) ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
                        </svg>
                        Year Level <span class="req">*</span>
                    </label>
                    <div class="input-wrap select-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
                        </svg>
                        <select name="year_level" class="form-control" required>
                            <option value="">-- Select Year --</option>
                            <?php for ($y = 1; $y <= 4; $y++): ?>
                                <option value="<?= $y ?>" <?= (($_POST['year_level'] ?? '') == $y) ? 'selected' : '' ?>>Year <?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Login Credentials -->
            <div class="section-divider"><span>Login Credentials</span></div>

            <div class="field">
                <label>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Username <span class="req">*</span>
                </label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <input type="text" name="username" class="form-control"
                        placeholder="Min. 3 chars, letters/numbers/dots/underscores"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Password <span class="req">*</span>
                    </label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input type="password" name="password" id="pw1" class="form-control"
                            placeholder="Min. 6 characters" required>
                    </div>
                </div>

                <div class="field">
                    <label>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Confirm Password <span class="req">*</span>
                    </label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input type="password" name="confirm_password" id="pw2" class="form-control"
                            placeholder="Re-enter password"
                            oninput="checkPwMatch()" required>
                    </div>
                    <div class="field-hint" id="pw-hint"></div>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/>
                    <line x1="22" y1="11" x2="16" y2="11"/>
                </svg>
                Register
            </button>
        </form>

        <?php endif; ?>

        <div class="login-link" style="margin-top:1.2rem;">
            Already have an account? <a href="<?= BASE_URL ?>login.php">Login here</a>
        </div>
    </div>
</div>

<script>
function validateStudentNo(input) {
    const val  = input.value.toUpperCase();
    input.value = val;
    const hint = document.getElementById('sno-hint');
    const ok   = /^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/.test(val);
    if (!val) {
        hint.textContent = 'Format: C26-01-0001-MAN121';
        hint.className   = 'field-hint';
    } else if (ok) {
        hint.textContent = '✓ Valid format';
        hint.className   = 'field-hint ok';
    } else {
        hint.textContent = '✗ Must follow: C26-01-0001-MAN121';
        hint.className   = 'field-hint error';
    }
}

function checkPwMatch() {
    const pw1  = document.getElementById('pw1').value;
    const pw2  = document.getElementById('pw2').value;
    const hint = document.getElementById('pw-hint');
    if (!pw2) { hint.textContent = ''; return; }
    if (pw1 === pw2) {
        hint.textContent = '✓ Passwords match';
        hint.className   = 'field-hint ok';
    } else {
        hint.textContent = '✗ Passwords do not match';
        hint.className   = 'field-hint error';
    }
}
</script>
</body>09053519376jeff
</html>