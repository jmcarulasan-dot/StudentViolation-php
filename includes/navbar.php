<?php
if (!defined('FONT_LOADED')): define('FONT_LOADED', true); ?>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800&display=swap" rel="stylesheet">
<?php endif; ?>

<?php
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? '';
$roleLabel = ucfirst($role);

$dashboardLink = BASE_URL;
if ($role === 'student')  $dashboardLink = BASE_URL . 'student/dashboard.php';
if ($role === 'guard')    $dashboardLink = BASE_URL . 'guard/dashboard.php';
if ($role === 'guidance') $dashboardLink = BASE_URL . 'guidance/dashboard.php';

$roleColors = [
    'student'  => '#3b82f6',
    'guard'    => '#e84545',
    'guidance' => '#8b5cf6',
];
$roleColor = $roleColors[$role] ?? '#1a3a5c';
?>
<nav class="navbar">
    <a href="<?= $dashboardLink ?>" class="brand">
        <!-- ACLC Logo instead of shield emoji -->
        <div class="nav-logo" style="background:white; border-radius:8px; padding:3px;">
            <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC Logo">
        </div>
        <div class="nav-brand-text">
            <span class="nav-title">ACLC — SVS</span>
            <span class="nav-sub">Student Violation System</span>
        </div>
    </a>

    <div class="nav-user">
        <div class="nav-role-badge" style="background:<?= $roleColor ?>20; color:<?= $roleColor ?>; border:1px solid <?= $roleColor ?>40;">
            <?= $roleLabel ?>
        </div>
        <span class="nav-name">👤 <?= htmlspecialchars($name) ?></span>
        <?php if ($role === 'student'): ?>
            <a href="<?= BASE_URL ?>student/profile.php" class="nav-link">Profile</a>
        <?php endif; ?>
        <a href="#" class="nav-logout" onclick="document.getElementById('logoutModal').style.display='flex'; return false;">Logout</a>
    </div>
</nav>

<!-- Logout Modal -->
<div id="logoutModal"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5);
            z-index:9999; align-items:center; justify-content:center;"
     onclick="if(event.target===this) this.style.display='none';">
    <div style="background:#fff; border-radius:16px; padding:2rem; max-width:360px;
                width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.25); text-align:center;">
        <!-- ACLC Logo in modal too -->
        <div style="width:56px; height:56px; margin:0 auto 0.8rem; border-radius:50%;
                    background:#f0f4f8; border:2px solid #e2e8f0;
                    display:flex; align-items:center; justify-content:center; overflow:hidden;">
            <img src="<?= BASE_URL ?>assets/image/ACLC.png" alt="ACLC"
                 style="width:40px; height:40px; object-fit:contain;">
        </div>
        <h3 style="margin:0 0 .4rem; font-size:1.1rem; font-weight:700; color:#1a202c;">Log out?</h3>
        <p style="color:#718096; font-size:.88rem; margin:0 0 1.5rem;">Are you sure you want to end your session?</p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="document.getElementById('logoutModal').style.display='none'"
                    style="padding:.55rem 1.4rem; border-radius:8px; border:1.5px solid #e2e8f0;
                           background:#fff; cursor:pointer; font-size:.9rem; font-weight:500; color:#4a5568;">
                Cancel
            </button>
            <a href="<?= BASE_URL ?>logout.php"
               style="padding:.55rem 1.4rem; border-radius:8px; background:#e53e3e;
                      color:#fff; text-decoration:none; font-size:.9rem; font-weight:600;
                      display:inline-flex; align-items:center;">
                Yes, Log Out
            </a>
        </div>
    </div>
</div>