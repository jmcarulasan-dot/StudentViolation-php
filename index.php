<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student')  header("Location: " . BASE_URL . "student/dashboard.php");
    if ($role === 'guard')    header("Location: " . BASE_URL . "guard/dashboard.php");
    if ($role === 'guidance') header("Location: " . BASE_URL . "guidance/dashboard.php");
} else {
    header("Location: " . BASE_URL . "login.php");
}
exit();
?>
