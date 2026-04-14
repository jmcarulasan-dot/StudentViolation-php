<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student')  { header("Location: " . BASE_URL . "student/dashboard.php"); exit(); }
    if ($role === 'guard')    { header("Location: " . BASE_URL . "guard/dashboard.php");   exit(); }
    if ($role === 'guidance') { header("Location: " . BASE_URL . "guidance/dashboard.php"); exit(); }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['name']       = $user['name'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['student_id'] = (int)$user['student_id'];

            session_write_close();
            session_start();

            if ($user['role'] === 'student')  { header("Location: " . BASE_URL . "student/dashboard.php");  exit(); }
            if ($user['role'] === 'guard')    { header("Location: " . BASE_URL . "guard/dashboard.php");    exit(); }
            if ($user['role'] === 'guidance') { header("Location: " . BASE_URL . "guidance/dashboard.php"); exit(); }
            exit();
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

$unauthorized = isset($_GET['error']) && $_GET['error'] === 'unauthorized';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Login</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary:   #1a3a5c;
            --accent:    #e84545;
            --bg:        #f0f4f8;
            --white:     #ffffff;
            --text:      #1e2a3a;
            --muted:     #64748b;
            --border:    #cbd5e1;
            --border-focus: #1a3a5c;
            --input-bg:  #f8fafc;
            --radius:    12px;
            --radius-lg: 20px;
        }

        body, html {
            height: 100%;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow: hidden;
        }

        .login-bg {
            position: fixed;
            inset: 0;
            background: url('<?= BASE_URL ?>assets/image/BackGround.webp') center center / cover no-repeat;
            filter: blur(4px) brightness(0.45);
            transform: scale(1.08);
            z-index: 0;
        }

        .login-box {
            position: relative;
            z-index: 1;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 2.8rem 2.5rem 2.2rem;
            width: 100%;
            max-width: 420px;
            border: 2px solid rgba(255,255,255,0.6);
            box-shadow: 0 24px 80px rgba(0,0,0,0.35), 0 2px 0 rgba(255,255,255,0.5) inset;
        }

        .login-brand { text-align: center; margin-bottom: 2rem; }

        .logo-circle {
            width: 80px; height: 80px;
            background: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            border: 2.5px solid var(--border);
            box-shadow: 0 4px 16px rgba(26,58,92,0.12);
            overflow: hidden;
        }

        .logo-circle img { width: 62px; height: 62px; object-fit: contain; }

        .login-brand h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.3px;
            line-height: 1.3;
        }

        .login-brand .subtitle {
            display: inline-block;
            margin-top: 0.5rem;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 4px 14px;
            border-radius: 20px;
        }

        .alert {
            padding: 11px 14px;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1.5px solid #fecaca;
        }

        .alert-icon { width: 18px; height: 18px; flex-shrink: 0; }

        .sign-in-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 1.2rem;
        }

        .field { margin-bottom: 1rem; }

        .field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
            letter-spacing: 0.2px;
        }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px; height: 18px;
            color: var(--muted);
            pointer-events: none;
            flex-shrink: 0;
        }

        .input-wrap input {
            width: 100%;
            padding: 12px 42px 12px 44px;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.92rem;
            color: var(--text);
            background: var(--input-bg);
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        .input-wrap input:focus {
            border-color: var(--border-focus);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(26,58,92,0.08);
        }

        .input-wrap input::placeholder { color: #a0aec0; }

        .toggle-pw {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: color 0.2s, background 0.2s;
        }

        .toggle-pw:hover { color: var(--primary); background: rgba(26,58,92,0.06); }

        .login-btn {
            width: 100%;
            padding: 13px;
            margin-top: 0.6rem;
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

        .login-btn:hover {
            background: #14304d;
            border-color: #14304d;
            box-shadow: 0 4px 16px rgba(26,58,92,0.25);
        }

        .login-btn:active { transform: scale(0.98); }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 1.4rem 0 1.2rem;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1.5px;
            background: var(--border);
            border-radius: 2px;
        }

        .divider span { font-size: 0.78rem; color: var(--muted); font-weight: 500; white-space: nowrap; }

        .register-link { text-align: center; font-size: 0.85rem; color: var(--muted); }
        .register-link a { color: var(--primary); font-weight: 700; text-decoration: none; }
        .register-link a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .login-box { padding: 2rem 1.5rem 1.8rem; }
        }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-bg"></div>

    <div class="login-box">
        <div class="login-brand">
            <div class="logo-circle">
                <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC Logo">
            </div>
            <h1>ACLC College of Mandaue</h1>
            <span class="subtitle">Student Violation System</span>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($unauthorized): ?>
        <div class="alert alert-error">
            <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            You are not authorized to access that page.
        </div>
        <?php endif; ?>

        <div class="sign-in-label">Sign in to continue</div>

        <form method="POST">
            <div class="field">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="pwInput">Password</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <input type="password" id="pwInput" name="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw()" id="toggleBtn" title="Show/hide password">
                        <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Login
            </button>
        </form>

        <div class="divider"><span>or</span></div>

        <div class="register-link">
            Don't have an account? <a href="<?= BASE_URL ?>register.php">Register here</a>
        </div>
    </div>
</div>

<script>
const eyeOpen   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>`;

function togglePw() {
    const input    = document.getElementById('pwInput');
    const icon     = document.getElementById('eyeIcon');
    const isHidden = input.type === 'password';
    input.type     = isHidden ? 'text' : 'password';
    icon.innerHTML = isHidden ? eyeClosed : eyeOpen;
}
</script>
</body>
</html>