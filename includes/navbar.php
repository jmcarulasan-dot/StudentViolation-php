<?php
// includes/navbar.php
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? '';

$roleLabel = ucfirst($role);

$dashboardLink = BASE_URL;
if ($role === 'student')  $dashboardLink = BASE_URL . 'student/dashboard.php';
if ($role === 'guard')    $dashboardLink = BASE_URL . 'guard/dashboard.php';
if ($role === 'guidance') $dashboardLink = BASE_URL . 'guidance/dashboard.php';
?>
<nav class="navbar">
    <a href="<?= $dashboardLink ?>" class="brand">
        🛡️ SVS <span><?= $roleLabel ?></span>
    </a>
    <div class="nav-user">
        <span>👤 <?= htmlspecialchars($name) ?></span>
        <?php if ($role === 'student'): ?>
            <a href="<?= BASE_URL ?>student/profile.php" style="color:rgba(255,255,255,0.8); text-decoration:none; font-size:0.85rem;">Profile</a>
        <?php endif; ?>
        <!-- Password link REMOVED — now lives inside profile.php -->
        <a href="#" onclick="document.getElementById('logoutModal').style.display='flex'; return false;"
           style="color:rgba(255,255,255,0.8); text-decoration:none; font-size:0.85rem;">Logout</a>
    </div>
</nav>

<!-- Logout Confirmation Modal -->
<div id="logoutModal"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5);
            z-index:9999; align-items:center; justify-content:center;"
     onclick="if(event.target===this) this.style.display='none';">
    <div style="background:#fff; border-radius:16px; padding:2rem; max-width:360px;
                width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.25); text-align:center;">
        <div style="font-size:2.5rem; margin-bottom:.5rem;">🚪</div>
        <h3 style="margin:0 0 .4rem; font-size:1.15rem; font-weight:700; color:#1a202c;">Log out?</h3>
        <p style="color:#718096; font-size:.9rem; margin:0 0 1.5rem;">
            Are you sure you want to end your session?
        </p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="document.getElementById('logoutModal').style.display='none'"
                    style="padding:.55rem 1.4rem; border-radius:8px; border:1.5px solid #e2e8f0;
                           background:#fff; cursor:pointer; font-size:.9rem; font-weight:500;
                           color:#4a5568;">
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