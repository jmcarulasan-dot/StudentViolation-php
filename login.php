<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student')  header("Location: " . BASE_URL . "student/dashboard.php");
    if ($role === 'guard')    header("Location: " . BASE_URL . "guard/dashboard.php");
    if ($role === 'guidance') header("Location: " . BASE_URL . "guidance/dashboard.php");
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
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['name']       = $user['name'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['student_id'] = $user['student_id'];

            if ($user['role'] === 'student')  header("Location: " . BASE_URL . "student/dashboard.php");
            if ($user['role'] === 'guard')    header("Location: " . BASE_URL . "guard/dashboard.php");
            if ($user['role'] === 'guidance') header("Location: " . BASE_URL . "guidance/dashboard.php");
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body, html { height: 100%; }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow: hidden;
        }

        /* Background image */
        .login-bg {
            position: fixed;
            inset: 0;
            background: url('<?= BASE_URL ?>assets/image/BackGround.webp') center center / cover no-repeat;
            filter: blur(3px) brightness(0.55);
            transform: scale(1.05);
            z-index: 0;
        }

        /* Card */
        .login-box {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            padding: 2.5rem 2.5rem 2rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        /* Logo + branding */
        .login-brand {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .login-brand .logo-circle {
            width: 85px; height: 85px;
            background: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 20px rgba(26,58,92,0.2);
            border: 3px solid #dce3ed;
            overflow: hidden;
        }

        .login-brand .logo-circle img {
            width: 65px; height: 65px;
            object-fit: contain;
        }

        .login-brand h1 {
            font-family: 'Syne', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: #1a3a5c;
            line-height: 1.3;
        }

        .login-brand .subtitle {
            display: inline-block;
            margin-top: 0.5rem;
            background: #1a3a5c;
            color: white;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 4px 14px;
            border-radius: 20px;
        }

        /* Sign in label */
        .sign-in-label {
            font-size: 0.82rem;
            color: #7a8b9a;
            font-weight: 500;
            margin-bottom: 1.2rem;
        }

        /* Inputs */
        .input-wrap {
            position: relative;
            margin-bottom: 1rem;
        }

        .input-wrap .icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            color: #7a8b9a;
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            padding: 12px 42px;
            border: 1.5px solid #dce3ed;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            color: #1e2a3a;
            background: #f4f6fa;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }

        .input-wrap input:focus {
            border-color: #1a3a5c;
            background: white;
        }

        .input-wrap .toggle-pw {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            color: #7a8b9a;
            padding: 0;
        }

        /* Login button */
        .login-btn {
            width: 100%;
            padding: 13px;
            background: #1a3a5c;
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 0.4rem;
        }

        .login-btn:hover  { background: #14304d; }
        .login-btn:active { transform: scale(0.98); }

        /* Register link */
        .register-link {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.83rem;
            color: #7a8b9a;
        }

        .register-link a {
            color: #1a3a5c;
            font-weight: 700;
            text-decoration: none;
        }

        .register-link a:hover { text-decoration: underline; }

        /* Divider */
        .divider {
            border: none;
            border-top: 1.5px solid #dce3ed;
            margin: 1.4rem 0 1.2rem;
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
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($unauthorized): ?>
            <div class="alert alert-error">You are not authorized to access that page.</div>
        <?php endif; ?>

        <div class="sign-in-label">Sign in to continue</div>

        <form method="POST">
            <div class="input-wrap">
                <span class="icon">👤</span>
                <input type="text" name="username" placeholder="Username" required autofocus>
            </div>
            <div class="input-wrap">
                <span class="icon">🔒</span>
                <input type="password" name="password" id="pwInput" placeholder="Password" required>
                <button type="button" class="toggle-pw" onclick="togglePw()" id="toggleBtn">🙈</button>
            </div>
            <button type="submit" class="login-btn">Login</button>
        </form>

        <hr class="divider">

        <div class="register-link">
            Don't have an account? <a href="<?= BASE_URL ?>register.php">Register here</a>
        </div>
    </div>
</div>

<script>
function togglePw() {
    const input = document.getElementById('pwInput');
    const btn   = document.getElementById('toggleBtn');
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '👁️';
    } else {
        input.type = 'password';
        btn.textContent = '🙈';
    }
}
</script>
</body>
</html>