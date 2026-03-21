<?php
// includes/navbar.php
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? '';

$roleLabel = ucfirst($role);
$roleBadge = "badge-{$role}";

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
        <a href="<?= BASE_URL ?>logout.php">Logout</a>
    </div>
</nav>
