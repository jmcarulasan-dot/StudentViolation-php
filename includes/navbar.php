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
        <a href="<?= BASE_URL ?>change_password.php" style="color:rgba(255,255,255,0.8); text-decoration:none; font-size:0.85rem;">Password</a>
        <a href="<?= BASE_URL ?>logout.php">Logout</a>
    </div>
</nav>