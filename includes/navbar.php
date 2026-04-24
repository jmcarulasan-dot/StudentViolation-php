<?php
if (!defined('FONT_LOADED')): define('FONT_LOADED', true); ?>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800&display=swap" rel="stylesheet">
<?php endif; ?>

<?php
$role      = $_SESSION['role'] ?? '';
$name      = $_SESSION['name'] ?? '';
$roleLabel = ucfirst($role);

$dashboardLink = BASE_URL;
if ($role === 'student')  $dashboardLink = BASE_URL . 'student/dashboard.php';
if ($role === 'guard')    $dashboardLink = BASE_URL . 'guard/dashboard.php';
if ($role === 'guidance') $dashboardLink = BASE_URL . 'guidance/dashboard.php';

$roleColors = [
    'student'  => '#3b82f6',
    'guard'    => '#f0a500',
    'guidance' => '#8b5cf6',
];
$roleColor = $roleColors[$role] ?? '#1a3a5c';
$roleIcons = ['student' => '🎓', 'guard' => '🛡️', 'guidance' => '👩‍💼'];
$roleIcon  = $roleIcons[$role] ?? '👤';
?>

<nav class="navbar">
    <a href="<?= $dashboardLink ?>" class="brand">
        <!-- ACLC logo on a crisp white rounded background -->
        <div style="
            width:42px; height:42px; min-width:42px;
            background:#ffffff;
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            box-shadow:0 2px 8px rgba(0,0,0,0.18), 0 0 0 1px rgba(255,255,255,0.25);
            overflow:hidden;
            flex-shrink:0;
        ">
            <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC"
                 style="width:34px; height:34px; object-fit:contain; display:block;">
        </div>
        <div class="nav-brand-text">
            <span class="nav-title">ACLC — SVS</span>
            <span class="nav-sub">Student Violation System</span>
        </div>
    </a>

    <div class="nav-user">
        <div class="nav-role-badge" style="
            background:<?= $roleColor ?>22;
            color:<?= $roleColor ?>;
            border:1.5px solid <?= $roleColor ?>55;
        ">
            <?= $roleIcon ?> <?= $roleLabel ?>
        </div>
        <span class="nav-name"><?= htmlspecialchars($name) ?></span>
        <?php if ($role === 'student'): ?>
            <a href="<?= BASE_URL ?>student/profile.php" class="nav-link">Profile</a>
        <?php endif; ?>
        <a href="#" class="nav-logout"
           onclick="document.getElementById('logoutModal').style.display='flex'; return false;">
           Logout
        </a>
    </div>
</nav>

<!-- Logout Modal -->
<div id="logoutModal"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55);
            z-index:9999; align-items:center; justify-content:center;
            backdrop-filter:blur(3px);"
     onclick="if(event.target===this) this.style.display='none';">
    <div style="background:#fff; border-radius:20px; padding:2.2rem 2rem; max-width:340px;
                width:90%; box-shadow:0 24px 60px rgba(0,0,0,0.3); text-align:center;
                border:1px solid #e2e8f0;">
        <div style="width:64px; height:64px; margin:0 auto .9rem; border-radius:16px;
                    background:#f0f4f8; border:2px solid #e2e8f0;
                    display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC"
                 style="width:48px; height:48px; object-fit:contain;">
        </div>
        <h3 style="margin:0 0 .4rem; font-size:1.1rem; font-weight:800; color:#1a202c; font-family:inherit;">
            Log out of SVS?
        </h3>
        <p style="color:#718096; font-size:.86rem; margin:0 0 1.6rem; line-height:1.5;">
            You will be returned to the login page.
        </p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="document.getElementById('logoutModal').style.display='none'"
                    style="padding:.6rem 1.5rem; border-radius:9px; border:1.5px solid #e2e8f0;
                           background:#fff; cursor:pointer; font-size:.88rem; font-weight:600;
                           color:#4a5568; font-family:inherit; transition:background .15s;">
                Cancel
            </button>
            <a href="<?= BASE_URL ?>logout.php"
               style="padding:.6rem 1.5rem; border-radius:9px; background:#e53e3e;
                      color:#fff; text-decoration:none; font-size:.88rem; font-weight:700;
                      display:inline-flex; align-items:center; gap:6px; font-family:inherit;">
                Yes, Log Out
            </a>
        </div>
    </div>
</div>