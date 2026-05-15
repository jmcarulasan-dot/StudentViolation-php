<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';

if (isLoggedIn()) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$step        = 'email'; // email → otp → reset
$error       = '';
$success     = '';

// ══ STEP 1 — Submit Email ════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'email') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
        $step  = 'email';
    } else {
        // Look up user by email
        $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            // Check students table too
            $stmt2 = $conn->prepare("
                SELECT u.id, u.name, u.email FROM users u
                JOIN students s ON u.student_id = s.id
                WHERE u.email = ? LIMIT 1
            ");
            $stmt2->bind_param("s", $email);
            $stmt2->execute();
            $user = $stmt2->get_result()->fetch_assoc();
        }

        if (!$user) {
            $error = "No account found with that email address.";
            $step  = 'email';
        } else {
            $otp = generateOTP();
            $_SESSION['_fp_otp']     = $otp;
            $_SESSION['_fp_expiry']  = time() + 300;
            $_SESSION['_fp_user_id'] = $user['id'];
            $_SESSION['_fp_email']   = $email;
            $_SESSION['_fp_attempts']= 0;

            $sent = sendOTPEmail($email, $user['name'], $otp);
            if ($sent) {
                $step = 'otp';
            } else {
                $error = "Failed to send OTP. Please try again.";
                $step  = 'email';
            }
        }
    }
}

// ══ STEP 2 — Verify OTP ══════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'otp') {
    $step = 'otp';
    $entered = trim($_POST['otp_code'] ?? '');

    $_SESSION['_fp_attempts'] = ($_SESSION['_fp_attempts'] ?? 0) + 1;

    if ($_SESSION['_fp_attempts'] > 5) {
        $error = "Too many attempts. Please start over.";
        foreach (['_fp_otp','_fp_expiry','_fp_user_id','_fp_email','_fp_attempts','_fp_verified'] as $k) unset($_SESSION[$k]);
        $step = 'email';
    } elseif (empty($_SESSION['_fp_otp'])) {
        $error = "Session expired. Please start over.";
        $step  = 'email';
    } elseif (time() > ($_SESSION['_fp_expiry'] ?? 0)) {
        $error = "OTP expired. Please start over.";
        foreach (['_fp_otp','_fp_expiry','_fp_user_id','_fp_email','_fp_attempts','_fp_verified'] as $k) unset($_SESSION[$k]);
        $step = 'email';
    } elseif ($entered !== $_SESSION['_fp_otp']) {
        $remaining = 5 - $_SESSION['_fp_attempts'];
        $error = "Incorrect OTP. {$remaining} attempt(s) remaining.";
    } else {
        $_SESSION['_fp_verified'] = true;
        unset($_SESSION['_fp_otp']);
        $step = 'reset';
    }
}

// ══ STEP 3 — Reset Password ══════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'reset') {
    $step = 'reset';

    if (empty($_SESSION['_fp_verified']) || empty($_SESSION['_fp_user_id'])) {
        $error = "Session expired. Please start over.";
        $step  = 'email';
    } else {
        $new_pw  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new_pw) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($new_pw !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $uid    = $_SESSION['_fp_user_id'];
            $stmt   = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $uid);
            if ($stmt->execute()) {
                foreach (['_fp_otp','_fp_expiry','_fp_user_id','_fp_email','_fp_attempts','_fp_verified'] as $k) unset($_SESSION[$k]);
                $success = "Password reset successfully! You can now log in.";
                $step    = 'done';
            } else {
                $error = "Failed to reset password. Please try again.";
            }
        }
    }
}

// ══ RESEND OTP ════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'resend_fp') {
    $step = 'otp';
    if (empty($_SESSION['_fp_email'])) {
        $error = "Session expired. Please start over.";
        $step  = 'email';
    } else {
        $otp = generateOTP();
        $_SESSION['_fp_otp']      = $otp;
        $_SESSION['_fp_expiry']   = time() + 300;
        $_SESSION['_fp_attempts'] = 0;
        $sent = sendOTPEmail($_SESSION['_fp_email'], 'User', $otp);
        $success = $sent ? "New OTP sent to your email." : "Failed to resend OTP.";
    }
}

// If session already has verified fp data, restore step
if (empty($_POST['step'])) {
    if (!empty($_SESSION['_fp_verified']))    $step = 'reset';
    elseif (!empty($_SESSION['_fp_otp']))     $step = 'otp';
    else                                       $step = 'email';
}

function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Forgot Password</title>
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --primary:#1a3a5c; --accent:#e84545; --bg:#f0f4f8;
            --white:#ffffff; --text:#1e2a3a; --muted:#64748b;
            --border:#cbd5e1; --radius:12px; --radius-lg:20px;
        }
        body { min-height:100vh; font-family:'Segoe UI',system-ui,sans-serif; }

        .page {
            min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:2rem 1rem; position:relative; overflow:hidden;
        }
        .bg {
            position:fixed; inset:0;
            background:url('<?= BASE_URL ?>assets/image/BackGround.webp') center/cover no-repeat;
            filter:blur(4px) brightness(0.45); transform:scale(1.08); z-index:0;
        }
        .box {
            position:relative; z-index:1; background:white;
            border-radius:var(--radius-lg); padding:2.8rem 2.5rem 2.2rem;
            width:100%; max-width:420px;
            border:2px solid rgba(255,255,255,.6);
            box-shadow:0 24px 80px rgba(0,0,0,.35);
        }

        .brand { text-align:center; margin-bottom:2rem; }
        .logo-circle {
            width:72px; height:72px; background:white; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto .9rem; border:2.5px solid var(--border);
            box-shadow:0 4px 16px rgba(26,58,92,.12); overflow:hidden;
        }
        .logo-circle img { width:56px; height:56px; object-fit:contain; }
        .brand h1 { font-size:1.15rem; font-weight:700; color:var(--primary); }
        .brand p  { font-size:.82rem; color:var(--muted); margin-top:3px; }

        .step-indicator {
            display:flex; justify-content:center; gap:8px; margin-bottom:1.8rem;
        }
        .step-dot {
            width:10px; height:10px; border-radius:50%;
            background:var(--border); transition:background .3s;
        }
        .step-dot.active  { background:var(--primary); }
        .step-dot.done    { background:var(--accent); }

        .alert {
            padding:11px 14px; border-radius:10px; margin-bottom:1.2rem;
            font-size:.875rem; font-weight:500; display:flex; align-items:center;
            gap:8px; border:1.5px solid;
        }
        .alert-error   { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        .alert-success { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
        .alert-info    { background:#eff6ff; color:#1e40af; border-color:#bfdbfe; }

        .step-title { font-size:1.05rem; font-weight:800; color:var(--primary); margin-bottom:.3rem; }
        .step-sub   { font-size:.83rem; color:var(--muted); margin-bottom:1.4rem; line-height:1.5; }

        .field { margin-bottom:1rem; }
        .field label { display:block; font-size:.82rem; font-weight:600; color:var(--text); margin-bottom:6px; }
        .input-wrap { position:relative; }
        .input-icon {
            position:absolute; left:13px; top:50%; transform:translateY(-50%);
            width:17px; height:17px; color:var(--muted); pointer-events:none;
        }
        input.fc {
            width:100%; padding:11px 14px 11px 42px;
            border:2px solid var(--border); border-radius:var(--radius);
            font-family:inherit; font-size:.9rem; color:var(--text);
            background:#f8fafc; outline:none;
            transition:border-color .2s, box-shadow .2s;
        }
        input.fc:focus { border-color:var(--primary); background:white; box-shadow:0 0 0 3px rgba(26,58,92,.08); }
        input.fc::placeholder { color:#a0aec0; }

        .btn-primary {
            width:100%; padding:13px; margin-top:.4rem;
            background:var(--primary); color:white; border:2px solid var(--primary);
            border-radius:var(--radius); font-family:inherit; font-size:.92rem;
            font-weight:700; letter-spacing:1px; text-transform:uppercase;
            cursor:pointer; transition:background .2s, box-shadow .2s;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .btn-primary:hover { background:#14304d; box-shadow:0 4px 16px rgba(26,58,92,.25); }

        .btn-back {
            width:100%; padding:10px; margin-top:.5rem;
            background:transparent; color:var(--muted);
            border:2px solid var(--border); border-radius:var(--radius);
            font-family:inherit; font-size:.84rem; font-weight:600;
            cursor:pointer; transition:all .18s; text-decoration:none;
            display:block; text-align:center;
        }
        .btn-back:hover { border-color:var(--primary); color:var(--primary); }

        /* OTP Boxes */
        .otp-boxes { display:flex; gap:10px; justify-content:center; margin:1.2rem 0; }
        .otp-boxes input {
            width:48px; height:56px; text-align:center; font-size:1.4rem;
            font-weight:800; border:2px solid var(--border); border-radius:10px;
            background:#f8fafc; color:var(--text); outline:none;
            transition:border-color .2s, box-shadow .2s; font-family:inherit;
        }
        .otp-boxes input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,58,92,.08); background:white; }
        .otp-boxes input.filled { border-color:var(--primary); background:#eff6ff; }

        .resend-row { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:.8rem; font-size:.82rem; color:var(--muted); }
        .resend-btn { background:none; border:none; color:var(--primary); font-weight:700; cursor:pointer; font-size:.82rem; font-family:inherit; text-decoration:underline; padding:0; }
        .resend-btn:disabled { color:var(--muted); text-decoration:none; cursor:default; }
        #fp-countdown { font-weight:700; color:var(--accent); }

        /* Success state */
        .success-state { text-align:center; padding:1rem 0; }
        .success-icon {
            width:64px; height:64px; background:#f0fdf4; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 1rem; border:2px solid #bbf7d0; font-size:1.8rem;
        }
        .success-state h3 { font-size:1.1rem; font-weight:800; color:var(--primary); margin-bottom:.4rem; }
        .success-state p  { color:var(--muted); font-size:.88rem; margin-bottom:1.4rem; }
        .go-login {
            display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
            background:var(--primary); color:white; border-radius:var(--radius);
            font-family:inherit; font-size:.9rem; font-weight:700;
            text-decoration:none; transition:background .2s;
        }
        .go-login:hover { background:#14304d; }

        @media(max-width:480px) {
            .box { padding:2rem 1.5rem 1.8rem; }
            .otp-boxes input { width:42px; height:50px; font-size:1.2rem; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="bg"></div>
    <div class="box">

        <div class="brand">
            <div class="logo-circle">
                <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC">
            </div>
            <h1>Forgot Password</h1>
            <p>ACLC College of Mandaue — SVS</p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot <?= in_array($step, ['email','otp','reset','done']) ? 'done' : '' ?> <?= $step === 'email' ? 'active' : '' ?>"></div>
            <div class="step-dot <?= in_array($step, ['otp','reset','done']) ? 'done' : '' ?> <?= $step === 'otp' ? 'active' : '' ?>"></div>
            <div class="step-dot <?= in_array($step, ['reset','done']) ? 'done' : '' ?> <?= $step === 'reset' ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error):   ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success && $step !== 'done'): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

        <?php if ($step === 'done'): ?>
        <!-- ══ DONE ══ -->
        <div class="success-state">
            <div class="success-icon">✅</div>
            <h3>Password Reset!</h3>
            <p>Your password has been updated successfully. You can now log in with your new password.</p>
            <a href="<?= BASE_URL ?>login.php" class="go-login">→ Go to Login</a>
        </div>

        <?php elseif ($step === 'email'): ?>
        <!-- ══ STEP 1: EMAIL ══ -->
        <div class="step-title">Enter your email</div>
        <div class="step-sub">We'll send a 6-digit OTP to verify your identity.</div>
        <form method="POST">
            <input type="hidden" name="step" value="email">
            <div class="field">
                <label>Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,12 2,6"/>
                    </svg>
                    <input type="email" name="email" class="fc" placeholder="yourname@gmail.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <button type="submit" class="btn-primary">Send OTP →</button>
        </form>
        <a href="<?= BASE_URL ?>login.php" class="btn-back">← Back to Login</a>

        <?php elseif ($step === 'otp'): ?>
        <!-- ══ STEP 2: OTP ══ -->
        <div class="step-title">Check your email</div>
        <div class="step-sub">
            Enter the 6-digit code sent to<br>
            <strong><?= htmlspecialchars(maskEmail($_SESSION['_fp_email'] ?? '')) ?></strong><br>
            <span style="font-size:.76rem;">Expires in 5 minutes.</span>
        </div>
        <form method="POST" id="fpOtpForm" onsubmit="combineFpOtp()">
            <input type="hidden" name="step" value="otp">
            <input type="hidden" name="otp_code" id="fp_otp_combined">
            <div class="otp-boxes">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
                <input type="text" inputmode="numeric" maxlength="1" class="fp-digit" autocomplete="off">
            </div>
            <button type="submit" class="btn-primary" id="fpVerifyBtn" disabled>Verify OTP ✓</button>
        </form>
        <div class="resend-row">
            <span>Didn't get it?</span>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="step" value="resend_fp">
                <button type="submit" class="resend-btn" id="fpResendBtn" disabled>
                    Resend (<span id="fp-countdown">60</span>s)
                </button>
            </form>
        </div>
        <a href="<?= BASE_URL ?>forgot_password.php" class="btn-back" style="margin-top:1rem;">← Start Over</a>

        <?php elseif ($step === 'reset'): ?>
        <!-- ══ STEP 3: RESET ══ -->
        <div class="step-title">Set new password</div>
        <div class="step-sub">Choose a strong password for your account.</div>
        <form method="POST">
            <input type="hidden" name="step" value="reset">
            <div class="field">
                <label>New Password *</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <input type="password" name="new_password" id="np" class="fc" placeholder="Min. 6 characters" required>
                </div>
            </div>
            <div class="field">
                <label>Confirm New Password *</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <input type="password" name="confirm_password" id="cp" class="fc"
                           placeholder="Re-enter password" oninput="checkMatch()" required>
                </div>
                <small id="matchMsg" style="display:none; font-size:.8rem; margin-top:4px;"></small>
            </div>
            <button type="submit" class="btn-primary">Reset Password ✓</button>
        </form>
        <?php endif; ?>

    </div>
</div>

<script>
// ── OTP digit boxes ───────────────────────────────────────
(function(){
    const digits = document.querySelectorAll('.fp-digit');
    const btn    = document.getElementById('fpVerifyBtn');
    if (!digits.length) return;

    function updateBtn() {
        btn.disabled = ![...digits].every(d => d.value.match(/^\d$/));
    }

    digits.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g,'').slice(-1);
            box.classList.toggle('filled', box.value !== '');
            if (box.value && i < digits.length - 1) digits[i+1].focus();
            updateBtn();
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                digits[i-1].focus();
                digits[i-1].value = '';
                digits[i-1].classList.remove('filled');
                updateBtn();
            }
        });
        box.addEventListener('paste', e => {
            e.preventDefault();
            const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
            p.split('').slice(0,6).forEach((ch,idx) => {
                if (digits[idx]) { digits[idx].value = ch; digits[idx].classList.add('filled'); }
            });
            updateBtn();
            const next = [...digits].findIndex(d => !d.value);
            (next !== -1 ? digits[next] : digits[5]).focus();
        });
    });
    digits[0]?.focus();
})();

function combineFpOtp() {
    document.getElementById('fp_otp_combined').value =
        [...document.querySelectorAll('.fp-digit')].map(d => d.value).join('');
}

// ── Resend countdown ──────────────────────────────────────
(function(){
    const btn = document.getElementById('fpResendBtn');
    const cd  = document.getElementById('fp-countdown');
    if (!btn || !cd) return;
    let secs = 60;
    const t = setInterval(() => {
        secs--;
        cd.textContent = secs;
        if (secs <= 0) {
            clearInterval(t);
            btn.disabled = false;
            btn.textContent = 'Resend OTP';
        }
    }, 1000);
})();

// ── Password match ────────────────────────────────────────
function checkMatch() {
    const np  = document.getElementById('np')?.value;
    const cp  = document.getElementById('cp')?.value;
    const msg = document.getElementById('matchMsg');
    if (!msg || !cp) return;
    msg.style.display = 'block';
    if (np === cp) { msg.style.color = 'green'; msg.textContent = '✅ Passwords match'; }
    else           { msg.style.color = 'red';   msg.textContent = '❌ Do not match'; }
}
</script>
</body>
</html>