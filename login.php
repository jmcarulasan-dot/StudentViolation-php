<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student') {
        header("Location: " . BASE_URL . "student/dashboard.php");
        exit();
    }
    if ($role === 'guard') {
        header("Location: " . BASE_URL . "guard/dashboard.php");
        exit();
    }
    if ($role === 'guidance') {
        header("Location: " . BASE_URL . "guidance/dashboard.php");
        exit();
    }
    exit();
}

$error = '';
$otpSent = false;   // true when we are on the OTP verification step
$otpError = '';
$resendSuccess = '';

// ══ STEP 1 — Username + Password ════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'credentials') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {

            // Fetch email from users table
            $email = $user['email'] ?? '';
            $name = $user['name'] ?? $username;

            // If no email on user row, try the students table
            if (empty($email) && !empty($user['student_id'])) {
                $stmt2 = $conn->prepare("SELECT email FROM students WHERE id = ?");
                if ($stmt2) {
                    $stmt2->bind_param("i", $user['student_id']);
                    $stmt2->execute();
                    $sRow = $stmt2->get_result()->fetch_assoc();
                    $email = $sRow['email'] ?? '';

                }
            }
            if (empty($email)) {
                // No email on record — allow login without OTP (guard accounts etc.)
                // Uncomment the block below if you want to BLOCK login when no email exists.
                // $error = "No email address is linked to your account. Contact the SAO office.";

                // ── Direct login (no email on record) ──
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['student_id'] = (int) $user['student_id'];

                session_write_close();
                session_start();

                if ($user['role'] === 'student') {
                    header("Location: " . BASE_URL . "student/dashboard.php");
                    exit();
                }
                if ($user['role'] === 'guard') {
                    header("Location: " . BASE_URL . "guard/dashboard.php");
                    exit();
                }
                if ($user['role'] === 'guidance') {
                    header("Location: " . BASE_URL . "guidance/dashboard.php");
                    exit();
                }
                exit();
            }

            // Generate and send OTP
            $otp = generateOTP();

            // Store in session (temporarily — not logged in yet)
            $_SESSION['_otp_code'] = $otp;
            $_SESSION['_otp_expiry'] = time() + 300; // 5 minutes
            $_SESSION['_otp_user_id'] = (int) $user['id'];
            $_SESSION['_otp_username'] = $user['username'];
            $_SESSION['_otp_name'] = $user['name'];
            $_SESSION['_otp_role'] = $user['role'];
            $_SESSION['_otp_student_id'] = (int) $user['student_id'];
            $_SESSION['_otp_email'] = $email;
            $_SESSION['_otp_attempts'] = 0;

            $sent = sendOTPEmail($email, $name, $otp);

            if ($sent) {
                $otpSent = true;
            } else {
                $error = "Failed to send OTP email. Please check the mailer configuration or try again.";
                // Clear pending OTP
                foreach ([
                    '_otp_code',
                    '_otp_expiry',
                    '_otp_user_id',
                    '_otp_username',
                    '_otp_name',
                    '_otp_role',
                    '_otp_student_id',
                    '_otp_email',
                    '_otp_attempts'
                ] as $k) {
                    unset($_SESSION[$k]);
                }
            }

        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

// ══ STEP 2 — OTP Verification ═══════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'otp') {

    $enteredOtp = trim($_POST['otp_code'] ?? '');
    $otpSent = true; // stay on OTP screen

    // Max attempts guard
    $_SESSION['_otp_attempts'] = ($_SESSION['_otp_attempts'] ?? 0) + 1;
    if ($_SESSION['_otp_attempts'] > 5) {
        $otpError = "Too many incorrect attempts. Please log in again.";
        foreach ([
            '_otp_code',
            '_otp_expiry',
            '_otp_user_id',
            '_otp_username',
            '_otp_name',
            '_otp_role',
            '_otp_student_id',
            '_otp_email',
            '_otp_attempts'
        ] as $k) {
            unset($_SESSION[$k]);
        }
        $otpSent = false;
    } elseif (empty($_SESSION['_otp_code'])) {
        $otpError = "Session expired. Please log in again.";
        $otpSent = false;
    } elseif (time() > ($_SESSION['_otp_expiry'] ?? 0)) {
        $otpError = "Your OTP has expired. Please log in again.";
        foreach ([
            '_otp_code',
            '_otp_expiry',
            '_otp_user_id',
            '_otp_username',
            '_otp_name',
            '_otp_role',
            '_otp_student_id',
            '_otp_email',
            '_otp_attempts'
        ] as $k) {
            unset($_SESSION[$k]);
        }
        $otpSent = false;
    } elseif ($enteredOtp !== $_SESSION['_otp_code']) {
        $remaining = 5 - $_SESSION['_otp_attempts'];
        $otpError = "Incorrect OTP code. {$remaining} attempt" . ($remaining !== 1 ? 's' : '') . " remaining.";
    } else {
        // ✅ OTP is correct — complete login
        $_SESSION['user_id'] = $_SESSION['_otp_user_id'];
        $_SESSION['name'] = $_SESSION['_otp_name'];
        $_SESSION['username'] = $_SESSION['_otp_username'];
        $_SESSION['role'] = $_SESSION['_otp_role'];
        $_SESSION['student_id'] = $_SESSION['_otp_student_id'];

        // Clear OTP data
        foreach ([
            '_otp_code',
            '_otp_expiry',
            '_otp_user_id',
            '_otp_username',
            '_otp_name',
            '_otp_role',
            '_otp_student_id',
            '_otp_email',
            '_otp_attempts'
        ] as $k) {
            unset($_SESSION[$k]);
        }

        session_write_close();
        session_start();

        $role = $_SESSION['role'];
        if ($role === 'student') {
            header("Location: " . BASE_URL . "student/dashboard.php");
            exit();
        }
        if ($role === 'guard') {
            header("Location: " . BASE_URL . "guard/dashboard.php");
            exit();
        }
        if ($role === 'guidance') {
            header("Location: " . BASE_URL . "guidance/dashboard.php");
            exit();
        }
        exit();
    }
}

// ══ RESEND OTP ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'resend') {
    $otpSent = true;

    if (empty($_SESSION['_otp_email'])) {
        $otpError = "Session expired. Please log in again.";
        $otpSent = false;
    } else {
        $otp = generateOTP();
        $_SESSION['_otp_code'] = $otp;
        $_SESSION['_otp_expiry'] = time() + 300;
        $_SESSION['_otp_attempts'] = 0;

        $sent = sendOTPEmail($_SESSION['_otp_email'], $_SESSION['_otp_name'], $otp);
        $resendSuccess = $sent
            ? "A new OTP has been sent to your email."
            : "Failed to resend OTP. Please try again.";
    }
}

$unauthorized = isset($_GET['error']) && $_GET['error'] === 'unauthorized';

// Mask email for display: j***@gmail.com
function maskEmail(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1));
    return $masked . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Login</title>
    <style>
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1a3a5c;
            --accent: #e84545;
            --bg: #f0f4f8;
            --white: #ffffff;
            --text: #1e2a3a;
            --muted: #64748b;
            --border: #cbd5e1;
            --border-focus: #1a3a5c;
            --input-bg: #f8fafc;
            --radius: 12px;
            --radius-lg: 20px;
        }

        body,
        html {
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
            border: 2px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35), 0 2px 0 rgba(255, 255, 255, 0.5) inset;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-circle {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border: 2.5px solid var(--border);
            box-shadow: 0 4px 16px rgba(26, 58, 92, 0.12);
            overflow: hidden;
        }

        .logo-circle img {
            width: 62px;
            height: 62px;
            object-fit: contain;
        }

        .login-brand h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.3px;
            line-height: 1.3;
        }

        .login-brand .subtitle {
            display: inline-block;
            margin-top: .5rem;
            background: var(--primary);
            color: white;
            font-size: .7rem;
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
            font-size: .875rem;
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

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1.5px solid #bbf7d0;
        }

        .alert-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .sign-in-label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 1.2rem;
        }

        .field {
            margin-bottom: 1rem;
        }

        .field label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
            letter-spacing: .2px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--muted);
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            padding: 12px 42px 12px 44px;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: .92rem;
            color: var(--text);
            background: var(--input-bg);
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .input-wrap input:focus {
            border-color: var(--border-focus);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(26, 58, 92, 0.08);
        }

        .input-wrap input::placeholder {
            color: #a0aec0;
        }

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
            transition: color .2s, background .2s;
        }

        .toggle-pw:hover {
            color: var(--primary);
            background: rgba(26, 58, 92, .06);
        }

        .login-btn {
            width: 100%;
            padding: 13px;
            margin-top: .6rem;
            background: var(--primary);
            color: white;
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: .92rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background .2s, transform .1s, box-shadow .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .login-btn:hover {
            background: #14304d;
            border-color: #14304d;
            box-shadow: 0 4px 16px rgba(26, 58, 92, .25);
        }

        .login-btn:active {
            transform: scale(.98);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 1.4rem 0 1.2rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1.5px;
            background: var(--border);
            border-radius: 2px;
        }

        .divider span {
            font-size: .78rem;
            color: var(--muted);
            font-weight: 500;
            white-space: nowrap;
        }

        .register-link {
            text-align: center;
            font-size: .85rem;
            color: var(--muted);
        }

        .register-link a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* ── OTP Step ── */
        .otp-header {
            text-align: center;
            margin-bottom: 1.6rem;
        }

        .otp-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #eff6ff;
            border: 2px solid #bfdbfe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto .9rem;
        }

        .otp-header h2 {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--primary);
        }

        .otp-header p {
            font-size: .84rem;
            color: var(--muted);
            margin-top: 5px;
            line-height: 1.5;
        }

        .otp-header strong {
            color: var(--text);
        }

        /* 6-box OTP input */
        .otp-boxes {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 1.4rem 0;
        }

        .otp-boxes input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: 0;
            border: 2px solid var(--border);
            border-radius: 10px;
            background: var(--input-bg);
            color: var(--text);
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            font-family: inherit;
        }

        .otp-boxes input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26, 58, 92, .08);
            background: white;
        }

        .otp-boxes input.filled {
            border-color: var(--primary);
            background: #eff6ff;
        }

        /* Hidden single OTP input for form submission */
        #otp_combined {
            display: none;
        }

        .resend-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: .8rem;
            font-size: .82rem;
            color: var(--muted);
        }

        .resend-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-weight: 700;
            cursor: pointer;
            font-size: .82rem;
            font-family: inherit;
            text-decoration: underline;
            padding: 0;
        }

        .resend-btn:disabled {
            color: var(--muted);
            text-decoration: none;
            cursor: default;
        }

        #countdown {
            font-weight: 700;
            color: var(--accent);
        }

        .back-btn {
            width: 100%;
            padding: 10px;
            margin-top: .5rem;
            background: transparent;
            color: var(--muted);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: .84rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .18s;
            text-align: center;
            display: block;
            text-decoration: none;
            letter-spacing: .2px;
        }

        .back-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        @media (max-width: 480px) {
            .login-box {
                padding: 2rem 1.5rem 1.8rem;
            }

            .otp-boxes input {
                width: 42px;
                height: 50px;
                font-size: 1.2rem;
            }
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

            <?php if (!$otpSent): ?>
                <!-- ════════════════════════════
             STEP 1 — Credentials
             ════════════════════════════ -->

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($unauthorized): ?>
                    <div class="alert alert-error">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        You are not authorized to access that page.
                    </div>
                <?php endif; ?>

                <div class="sign-in-label">Sign in to continue</div>

                <form method="POST">
                    <input type="hidden" name="step" value="credentials">

                    <div class="field">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            <input type="text" id="username" name="username" placeholder="Enter your username" required
                                autofocus>
                        </div>
                    </div>

                    <div class="field">
                        <label for="pwInput">Password</label>
                        <div class="input-wrap">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            <input type="password" id="pwInput" name="password" placeholder="Enter your password" required>
                            <button type="button" class="toggle-pw" onclick="togglePw()" title="Show/hide password">
                                <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                            <polyline points="10 17 15 12 10 7" />
                            <line x1="15" y1="12" x2="3" y2="12" />
                        </svg>
                        Login
                    </button>
                </form>

                <div class="divider"><span>or</span></div>

                <div class="register-link">
                    Don't have an account? <a href="<?= BASE_URL ?>register.php">Register here</a>
                </div>

            <?php else: ?>
                <!-- ════════════════════════════
             STEP 2 — OTP Verification
             ════════════════════════════ -->

                <?php if ($otpError): ?>
                    <div class="alert alert-error">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <?= htmlspecialchars($otpError) ?>
                    </div>
                <?php endif; ?>

                <?php if ($resendSuccess): ?>
                    <div class="alert alert-success">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        <?= htmlspecialchars($resendSuccess) ?>
                    </div>
                <?php endif; ?>

                <div class="otp-header">
                    <div class="otp-icon">📧</div>
                    <h2>Check Your Email</h2>
                    <p>
                        We sent a 6-digit OTP to<br>
                        <strong><?= htmlspecialchars(maskEmail($_SESSION['_otp_email'] ?? '')) ?></strong><br>
                        <span style="font-size:.78rem;">It expires in 5 minutes.</span>
                    </p>
                </div>

                <form method="POST" id="otpForm" onsubmit="combineOtp()">
                    <input type="hidden" name="step" value="otp">
                    <input type="hidden" name="otp_code" id="otp_combined">

                    <!-- 6 individual digit boxes -->
                    <div class="otp-boxes">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d1" autocomplete="off">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d2" autocomplete="off">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d3" autocomplete="off">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d4" autocomplete="off">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d5" autocomplete="off">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" id="d6" autocomplete="off">
                    </div>

                    <button type="submit" class="login-btn" id="verifyBtn" disabled>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Verify OTP
                    </button>
                </form>

                <!-- Resend OTP -->
                <div class="resend-row">
                    <span>Didn't receive it?</span>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="step" value="resend">
                        <button type="submit" class="resend-btn" id="resendBtn" disabled>
                            Resend OTP (<span id="countdown">60</span>s)
                        </button>
                    </form>
                </div>

                <!-- Back to login -->
                <a href="<?= BASE_URL ?>login.php" class="back-btn" style="margin-top:1rem;" onclick="clearOtpSession()">←
                    Back to Login</a>

            <?php endif; ?>
        </div>
    </div>

    <script>
        /* ── Password toggle ─────────────────────────────────────── */
        const eyeOpen = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
        const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>`;

        function togglePw() {
            const input = document.getElementById('pwInput');
            const icon = document.getElementById('eyeIcon');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.innerHTML = isHidden ? eyeClosed : eyeOpen;
        }

        /* ── OTP digit boxes ─────────────────────────────────────── */
        (function () {
            const digits = document.querySelectorAll('.otp-digit');
            const verifyBtn = document.getElementById('verifyBtn');
            if (!digits.length) return;

            function updateVerifyBtn() {
                const allFilled = [...digits].every(d => d.value.match(/^\d$/));
                verifyBtn.disabled = !allFilled;
            }

            digits.forEach((box, i) => {
                box.addEventListener('input', () => {
                    box.value = box.value.replace(/\D/g, '').slice(-1);
                    box.classList.toggle('filled', box.value !== '');
                    if (box.value && i < digits.length - 1) digits[i + 1].focus();
                    updateVerifyBtn();
                });

                box.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !box.value && i > 0) {
                        digits[i - 1].focus();
                        digits[i - 1].value = '';
                        digits[i - 1].classList.remove('filled');
                        updateVerifyBtn();
                    }
                });

                // Support paste across all boxes
                box.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                    pasted.split('').slice(0, 6).forEach((ch, idx) => {
                        if (digits[idx]) {
                            digits[idx].value = ch;
                            digits[idx].classList.add('filled');
                        }
                    });
                    updateVerifyBtn();
                    const nextEmpty = [...digits].findIndex(d => !d.value);
                    if (nextEmpty !== -1) digits[nextEmpty].focus();
                    else digits[5].focus();
                });
            });

            // Auto-focus first box
            digits[0].focus();
        })();

        function combineOtp() {
            const digits = document.querySelectorAll('.otp-digit');
            document.getElementById('otp_combined').value = [...digits].map(d => d.value).join('');
        }

        /* ── Resend countdown ────────────────────────────────────── */
        (function () {
            const btn = document.getElementById('resendBtn');
            const cd = document.getElementById('countdown');
            if (!btn || !cd) return;

            let secs = 60;
            const timer = setInterval(() => {
                secs--;
                cd.textContent = secs;
                if (secs <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    btn.textContent = 'Resend OTP';
                }
            }, 1000);
        })();

        function clearOtpSession() {
            // POST a hidden form to clear the OTP session server-side on navigate back
            // Actual cleanup is handled by PHP session destruction on the next login attempt
        }
    </script>
</body>

</html>