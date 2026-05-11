<?php
if (!defined('FONT_LOADED')): define('FONT_LOADED', true); ?>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700,800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css" rel="stylesheet">
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
$roleIcons = ['student' => 'ti-school', 'guard' => 'ti-shield', 'guidance' => 'ti-user-star'];
$roleIcon  = $roleIcons[$role] ?? 'ti-user';

// Profile photo for student nav avatar
$navPhotoUrl = '';
if ($role === 'student' && isset($_SESSION['student_id'])) {
    if (!isset($_SESSION['_nav_photo'])) {
        global $conn;
        $sid  = intval($_SESSION['student_id']);
        $stmt = $conn->prepare("SELECT profile_photo FROM students WHERE id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $photoRow = $stmt->get_result()->fetch_assoc();
        $_SESSION['_nav_photo'] = $photoRow['profile_photo'] ?? '';
    }
    if (!empty($_SESSION['_nav_photo'])) {
        $navPhotoUrl = BASE_URL . 'uploads/profile/' . htmlspecialchars($_SESSION['_nav_photo']);
    }
}

// ── Notification data per role ────────────────────────────
$notifications = [];
$notifCount    = 0;
global $conn;

if ($role === 'guidance') {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM violations WHERE appeal_status='pending'");
    $cnt = $res ? $res->fetch_assoc()['cnt'] : 0;
    if ($cnt > 0) {
        $notifications[] = ['icon'=>'ti-file-text','color'=>'#f0a500','text'=>"$cnt pending appeal" . ($cnt>1?'s':'') . " awaiting review",'time'=>'Now'];
    }
    $res2 = $conn->query("SELECT COUNT(*) AS cnt FROM violations WHERE status='pending'");
    $cnt2 = $res2 ? $res2->fetch_assoc()['cnt'] : 0;
    if ($cnt2 > 0) {
        $notifications[] = ['icon'=>'ti-alert-triangle','color'=>'#e84545','text'=>"$cnt2 unresolved violation" . ($cnt2>1?'s':''),'time'=>'Today'];
    }
} elseif ($role === 'guard') {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM violations WHERE DATE(date_recorded)=CURDATE()");
    $cnt = $res ? $res->fetch_assoc()['cnt'] : 0;
    if ($cnt > 0) {
        $notifications[] = ['icon'=>'ti-calendar','color'=>'#3b82f6','text'=>"$cnt violation" . ($cnt>1?'s':'') . " recorded today",'time'=>'Today'];
    }
    $res2 = $conn->query("SELECT COUNT(*) AS cnt FROM violations WHERE appeal_status='pending'");
    $cnt2 = $res2 ? $res2->fetch_assoc()['cnt'] : 0;
    if ($cnt2 > 0) {
        $notifications[] = ['icon'=>'ti-file-text','color'=>'#f0a500','text'=>"$cnt2 student appeal" . ($cnt2>1?'s':'') . " pending",'time'=>'Now'];
    }
} elseif ($role === 'student' && isset($_SESSION['student_id'])) {
    $sid  = intval($_SESSION['student_id']);
    $res  = $conn->prepare("SELECT COUNT(*) AS cnt FROM violations WHERE student_id=? AND status='pending'");
    $res->bind_param("i", $sid); $res->execute();
    $cnt  = $res->get_result()->fetch_assoc()['cnt'];
    if ($cnt > 0) {
        $notifications[] = ['icon'=>'ti-alert-triangle','color'=>'#e84545','text'=>"$cnt pending violation" . ($cnt>1?'s':'') . " on your record",'time'=>'Now'];
    }
    $res2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM violations WHERE student_id=? AND appeal_status='pending'");
    $res2->bind_param("i", $sid); $res2->execute();
    $cnt2 = $res2->get_result()->fetch_assoc()['cnt'];
    if ($cnt2 > 0) {
        $notifications[] = ['icon'=>'ti-clock','color'=>'#f0a500','text'=>"$cnt2 appeal" . ($cnt2>1?'s':'') . " awaiting guidance review",'time'=>'Pending'];
    }
    // Check if any appeal was recently resolved
    $res3 = $conn->prepare("SELECT COUNT(*) AS cnt FROM violations WHERE student_id=? AND appeal_status IN ('approved','rejected')");
    $res3->bind_param("i", $sid); $res3->execute();
    $cnt3 = $res3->get_result()->fetch_assoc()['cnt'];
    if ($cnt3 > 0) {
        $notifications[] = ['icon'=>'ti-check','color'=>'#2ecc71','text'=>"$cnt3 appeal" . ($cnt3>1?'s':'') . " have been reviewed",'time'=>'Recent'];
    }
}
$notifCount = count($notifications);
?>

<nav class="navbar">
    <!-- Mobile hamburger -->
    <button class="nav-hamburger" id="mobileMenuBtn" onclick="toggleMobileSidebar()" aria-label="Toggle menu" style="display:none;">
        <i class="ti ti-menu-2"></i>
    </button>

    <a href="<?= $dashboardLink ?>" class="brand">
        <div style="
            width:42px; height:42px; min-width:42px;
            background:#ffffff; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            box-shadow:0 2px 8px rgba(0,0,0,0.18), 0 0 0 1px rgba(255,255,255,0.25);
            overflow:hidden; flex-shrink:0;
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
        <!-- Role badge -->
        <div class="nav-role-badge" style="
            background:<?= $roleColor ?>22;
            color:<?= $roleColor ?>;
            border:1.5px solid <?= $roleColor ?>55;
            display:flex; align-items:center; gap:5px;
        ">
            <i class="ti <?= $roleIcon ?>" style="font-size:.85rem;"></i>
            <?= $roleLabel ?>
        </div>

        <!-- Student photo or name -->
        <?php if ($role === 'student'): ?>
            <?php if ($navPhotoUrl): ?>
                <img src="<?= $navPhotoUrl ?>" alt="Profile"
                     style="width:32px; height:32px; border-radius:50%; object-fit:cover;
                            border:2px solid rgba(255,255,255,.3);">
            <?php else: ?>
                <span class="nav-name"><?= htmlspecialchars($name) ?></span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>student/profile.php" class="nav-link">
                <i class="ti ti-user" style="font-size:.85rem;"></i> Profile
            </a>
        <?php else: ?>
            <span class="nav-name"><?= htmlspecialchars($name) ?></span>
        <?php endif; ?>

        <!-- Notification Bell -->
        <div class="notif-wrap">
            <div class="nav-bell" id="notifBell" onclick="toggleNotif()" title="Notifications">
                <i class="ti ti-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <div class="nav-bell-dot"></div>
                <?php endif; ?>
            </div>

            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <span>🔔 Notifications</span>
                    <?php if ($notifCount > 0): ?>
                        <span style="background:var(--accent);color:white;font-size:.65rem;font-weight:700;
                                     border-radius:10px;padding:1px 8px;"><?= $notifCount ?></span>
                    <?php endif; ?>
                </div>
                <?php if (empty($notifications)): ?>
                    <div class="notif-empty">
                        <i class="ti ti-check" style="font-size:1.5rem;display:block;margin-bottom:6px;color:var(--success);"></i>
                        All caught up! No new alerts.
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notif-item">
                        <div class="notif-dot" style="background:<?= $notif['color'] ?>;"></div>
                        <div>
                            <div class="notif-text"><?= htmlspecialchars($notif['text']) ?></div>
                            <div class="notif-time"><?= htmlspecialchars($notif['time']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Logout -->
        <a href="#" class="nav-logout"
           onclick="document.getElementById('logoutModal').style.display='flex'; return false;">
           <i class="ti ti-logout" style="font-size:.9rem;"></i> Logout
        </a>
    </div>
</nav>

<!-- Mobile sidebar overlay -->
<div class="sidebar-mobile-overlay" id="sidebarMobileOverlay" onclick="closeMobileSidebar()"></div>

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
                           color:#4a5568; font-family:inherit;">
                Cancel
            </button>
            <a href="<?= BASE_URL ?>logout.php"
               style="padding:.6rem 1.5rem; border-radius:9px; background:#e53e3e;
                      color:#fff; text-decoration:none; font-size:.88rem; font-weight:700;
                      display:inline-flex; align-items:center; gap:6px; font-family:inherit;">
                <i class="ti ti-logout"></i> Yes, Log Out
            </a>
        </div>
    </div>
</div>

<script>
// ── Notification dropdown ─────────────────────────────────
function toggleNotif() {
    const dd = document.getElementById('notifDropdown');
    dd.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const wrap = document.querySelector('.notif-wrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('open');
    }
});

// ── Mobile sidebar ────────────────────────────────────────
function toggleMobileSidebar() {
    const sb      = document.getElementById('svsSidebar');
    const overlay = document.getElementById('sidebarMobileOverlay');
    if (!sb) return;
    const isOpen = !sb.classList.contains('collapsed');
    if (isOpen) {
        sb.classList.add('collapsed');
        overlay.classList.remove('open');
    } else {
        sb.classList.remove('collapsed');
        overlay.classList.add('open');
    }
}

function closeMobileSidebar() {
    const sb      = document.getElementById('svsSidebar');
    const overlay = document.getElementById('sidebarMobileOverlay');
    if (sb) sb.classList.add('collapsed');
    if (overlay) overlay.classList.remove('open');
}

// Show hamburger on mobile
if (window.innerWidth <= 768) {
    const btn = document.getElementById('mobileMenuBtn');
    if (btn) btn.style.display = 'flex';
}
window.addEventListener('resize', () => {
    const btn = document.getElementById('mobileMenuBtn');
    if (btn) btn.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
});
</script>