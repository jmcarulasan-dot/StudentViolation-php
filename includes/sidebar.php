<?php
/**
 * includes/sidebar.php
 * Shared collapsible sidebar for all roles.
 * Usage: include '../includes/sidebar.php';  (or 'includes/sidebar.php' from root)
 * Requires: $_SESSION['role'], $_SESSION['name'], BASE_URL already defined.
 */

$role = $_SESSION['role'] ?? '';
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

// ── Nav items per role ────────────────────────────────────
$navItems = [];

if ($role === 'guidance') {
    $navItems = [
        'section' => 'Main',
        'items' => [
            ['icon' => 'ti-home',             'label' => 'Home',        'tab' => '',            'href' => BASE_URL . 'guidance/dashboard.php'],
            ['icon' => 'ti-layout-dashboard', 'label' => 'Overview',    'tab' => 'overview',    'href' => '#'],
            ['icon' => 'ti-chart-bar',        'label' => 'Analytics',   'tab' => 'analytics',   'href' => '#'],
        ],
        'section2' => 'Manage',
        'items2' => [
            ['icon' => 'ti-alert-triangle',   'label' => 'Violations',  'tab' => 'violations',  'href' => '#', 'badge' => 'violations'],
            ['icon' => 'ti-file-text',        'label' => 'Appeals',     'tab' => 'appeals',     'href' => '#', 'badge' => 'appeals'],
            ['icon' => 'ti-users',            'label' => 'Students',    'tab' => 'students',    'href' => '#'],
            ['icon' => 'ti-user-plus',        'label' => 'Add Student', 'tab' => 'add-student', 'href' => '#'],
        ],
        'bottom' => [
            ['icon' => 'ti-lock',    'label' => 'Change Password', 'href' => BASE_URL . 'change_password.php'],
            ['icon' => 'ti-logout',  'label' => 'Logout',          'href' => BASE_URL . 'logout.php', 'danger' => true],
        ],
    ];
}

if ($role === 'guard') {
    $navItems = [
        'section' => 'Main',
        'items' => [
            ['icon' => 'ti-home',           'label' => 'Home',              'tab' => '',       'href' => BASE_URL . 'guard/dashboard.php'],
            ['icon' => 'ti-plus',           'label' => 'Record Violation',  'tab' => 'record', 'href' => '#'],
            ['icon' => 'ti-search',         'label' => 'Student Lookup',    'tab' => 'lookup', 'href' => '#'],
        ],
        'section2' => 'Logs',
        'items2' => [
            ['icon' => 'ti-calendar',       'label' => "Today's Log",       'tab' => 'today',  'href' => '#', 'badge' => 'today'],
            ['icon' => 'ti-folder',         'label' => 'All Violations',    'tab' => 'all',    'href' => '#', 'badge' => 'violations'],
        ],
        'bottom' => [
            ['icon' => 'ti-lock',    'label' => 'Change Password', 'href' => BASE_URL . 'change_password.php'],
            ['icon' => 'ti-logout',  'label' => 'Logout',          'href' => BASE_URL . 'logout.php', 'danger' => true],
        ],
    ];
}

if ($role === 'student') {
    $navItems = [
        'section' => 'Main',
        'items' => [
            ['icon' => 'ti-home',        'label' => 'Home',          'tab' => '',        'href' => BASE_URL . 'student/dashboard.php'],
            ['icon' => 'ti-alert-triangle','label' => 'My Violations','tab' => 'violations','href' => '#'],
            ['icon' => 'ti-file-text',   'label' => 'My Appeals',    'tab' => 'appeals', 'href' => '#', 'badge' => 'appeals'],
        ],
        'section2' => 'Account',
        'items2' => [
            ['icon' => 'ti-user',        'label' => 'Profile',       'tab' => '',        'href' => BASE_URL . 'student/profile.php'],
            ['icon' => 'ti-lock',        'label' => 'Change Password','tab' => '',       'href' => BASE_URL . 'change_password.php'],
        ],
        'bottom' => [
            ['icon' => 'ti-logout',  'label' => 'Logout', 'href' => BASE_URL . 'logout.php', 'danger' => true],
        ],
    ];
}

// Badge counts (passed from the dashboard before including sidebar)
$sidebarBadges = $sidebarBadges ?? [];
?>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<div class="svs-sidebar" id="svsSidebar">

    <!-- Collapse toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
        <i class="ti ti-chevron-left" id="sidebarToggleIcon"></i>
    </button>

    <!-- Top nav items -->
    <?php if (!empty($navItems)): ?>

    <div class="sidebar-section-label"><?= $navItems['section'] ?></div>

    <?php foreach ($navItems['items'] as $item):
        $badge = isset($item['badge']) ? ($sidebarBadges[$item['badge']] ?? 0) : 0;
        $tabAttr = $item['tab'] ? "data-tab=\"{$item['tab']}\"" : '';
    ?>
    <a href="<?= $item['href'] ?>"
       class="sidebar-item <?= isset($item['danger']) ? 'danger' : '' ?>"
       <?= $tabAttr ?>
       <?= $item['tab'] ? 'onclick="sidebarNav(event,this)"' : '' ?>
       title="<?= htmlspecialchars($item['label']) ?>">
        <i class="ti <?= $item['icon'] ?> sidebar-icon" aria-hidden="true"></i>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
        <?php if ($badge > 0): ?>
            <span class="sidebar-badge"><?= $badge ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if (!empty($navItems['section2'])): ?>
    <div class="sidebar-divider"></div>
    <div class="sidebar-section-label"><?= $navItems['section2'] ?></div>

    <?php foreach ($navItems['items2'] as $item):
        $badge = isset($item['badge']) ? ($sidebarBadges[$item['badge']] ?? 0) : 0;
        $tabAttr = $item['tab'] ? "data-tab=\"{$item['tab']}\"" : '';
    ?>
    <a href="<?= $item['href'] ?>"
       class="sidebar-item <?= isset($item['danger']) ? 'danger' : '' ?>"
       <?= $tabAttr ?>
       <?= $item['tab'] ? 'onclick="sidebarNav(event,this)"' : '' ?>
       title="<?= htmlspecialchars($item['label']) ?>">
        <i class="ti <?= $item['icon'] ?> sidebar-icon" aria-hidden="true"></i>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
        <?php if ($badge > 0): ?>
            <span class="sidebar-badge"><?= $badge ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>
    <div class="sidebar-divider"></div>

    <?php foreach ($navItems['bottom'] as $item): ?>
    <a href="<?= $item['href'] ?>"
       class="sidebar-item <?= isset($item['danger']) ? 'danger' : '' ?>"
       title="<?= htmlspecialchars($item['label']) ?>"
       <?= isset($item['danger']) ? 'onclick="return confirmLogout()"' : '' ?>>
        <i class="ti <?= $item['icon'] ?> sidebar-icon" aria-hidden="true"></i>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
    </a>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
<!-- ══ END SIDEBAR ══════════════════════════════════════════ -->

<script>
// ── Sidebar collapse ──────────────────────────────────────
const SIDEBAR_KEY = 'svs_sidebar_collapsed';
let sidebarCollapsed = localStorage.getItem(SIDEBAR_KEY) === '1';

function applySidebar() {
    const sb   = document.getElementById('svsSidebar');
    const icon = document.getElementById('sidebarToggleIcon');
    const wrap = document.getElementById('svsLayout');
    if (sidebarCollapsed) {
        sb.classList.add('collapsed');
        if (wrap) wrap.classList.add('sidebar-collapsed');
        if (icon) { icon.classList.remove('ti-chevron-left'); icon.classList.add('ti-chevron-right'); }
    } else {
        sb.classList.remove('collapsed');
        if (wrap) wrap.classList.remove('sidebar-collapsed');
        if (icon) { icon.classList.remove('ti-chevron-right'); icon.classList.add('ti-chevron-left'); }
    }
}

function toggleSidebar() {
    sidebarCollapsed = !sidebarCollapsed;
    localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed ? '1' : '0');
    applySidebar();
}

applySidebar();

// ── Tab navigation via sidebar ────────────────────────────
function sidebarNav(e, el) {
    const tab = el.getAttribute('data-tab');
    if (!tab) return;
    e.preventDefault();

    // Mark active
    document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    // Switch tab (works with both guard and guidance switchTab functions)
    if (typeof switchTab === 'function') {
        const btn = document.getElementById('btn-' + tab);
        switchTab(tab, btn || el);
    }

    // On mobile: close sidebar after tap
    if (window.innerWidth < 768) {
        sidebarCollapsed = true;
        applySidebar();
    }
}

// ── Logout confirm ────────────────────────────────────────
function confirmLogout() {
    const modal = document.getElementById('logoutModal');
    if (modal) { modal.style.display = 'flex'; return false; }
    return confirm('Are you sure you want to logout?');
}

// ── Sync active state with current tab ───────────────────
function syncSidebarActive(tabName) {
    document.querySelectorAll('.sidebar-item[data-tab]').forEach(item => {
        item.classList.toggle('active', item.getAttribute('data-tab') === tabName);
    });
    // Home active when no tab
    if (!tabName) {
        document.querySelectorAll('.sidebar-item:not([data-tab])').forEach((item, i) => {
            if (i === 0) item.classList.add('active');
        });
    }
}
</script>