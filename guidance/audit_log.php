<?php
require_once '../includes/config.php';
requireLogin('guidance');

// Filters
$filterAction = $_GET['action'] ?? '';
$filterRole   = $_GET['role']   ?? '';
$filterFrom   = $_GET['from']   ?? '';
$filterTo     = $_GET['to']     ?? '';
$filterSearch = trim($_GET['search'] ?? '');

// Build query
$where  = [];
$params = [];
$types  = '';

if ($filterAction) { $where[] = "action = ?";           $params[] = $filterAction; $types .= 's'; }
if ($filterRole)   { $where[] = "user_role = ?";        $params[] = $filterRole;   $types .= 's'; }
if ($filterFrom)   { $where[] = "DATE(created_at) >= ?"; $params[] = $filterFrom;  $types .= 's'; }
if ($filterTo)     { $where[] = "DATE(created_at) <= ?"; $params[] = $filterTo;    $types .= 's'; }
if ($filterSearch) { $where[] = "(user_name LIKE ? OR details LIKE ?)";
                     $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $types .= 'ss'; }

$sql = "SELECT * FROM audit_logs" . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY created_at DESC LIMIT 500";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total = count($logs);

// Distinct actions for filter dropdown
$actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC);

$sidebarBadges = [
    'violations' => $conn->query("SELECT COUNT(*) AS c FROM violations")->fetch_assoc()['c'],
    'appeals'    => $conn->query("SELECT COUNT(*) AS c FROM violations WHERE appeal_status='pending'")->fetch_assoc()['c'],
];

$actionColors = [
    'LOGIN'                  => ['bg'=>'#dbeafe','color'=>'#1d4ed8'],
    'RECORD_VIOLATION'       => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'ADD_STUDENT'            => ['bg'=>'#d1fae5','color'=>'#065f46'],
    'DELETE_STUDENT'         => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'DELETE_VIOLATION'       => ['bg'=>'#fee2e2','color'=>'#991b1b'],
    'EDIT_VIOLATION'         => ['bg'=>'#fef3c7','color'=>'#92400e'],
    'UPDATE_VIOLATION_STATUS'=> ['bg'=>'#ede9fe','color'=>'#5b21b6'],
    'REVIEW_APPEAL'          => ['bg'=>'#d1fae5','color'=>'#065f46'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Audit Log</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        .action-badge {
            display: inline-block; padding: 2px 10px;
            border-radius: 20px; font-size: .68rem;
            font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
        }
        .filter-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr));
            gap: 1rem; margin-bottom: 1.2rem;
        }
        .log-row td { font-size: .82rem; }
        .log-details {
            font-size: .76rem; color: var(--muted);
            max-width: 260px; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
    </style>
</head>
<body>
<div class="svs-app">
<?php include '../includes/navbar.php'; ?>
<div class="svs-layout" id="svsLayout">
    <?php include '../includes/sidebar.php'; ?>
    <div class="svs-main">
        <div class="page-wrapper">
            <div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h2>📋 Audit Log</h2>
                    <p>Track all system actions — logins, violations, appeals, and student management.</p>
                </div>
                <a href="<?= BASE_URL ?>guidance/dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
            </div>

            <!-- Filters -->
            <div class="card">
                <div class="card-title">🔍 Filter Logs</div>
                <form method="GET">
                    <div class="filter-grid">
                        <div class="form-group" style="margin:0;">
                            <label>Action</label>
                            <select name="action" class="form-control">
                                <option value="">All Actions</option>
                                <?php foreach ($actions as $a): ?>
                                <option value="<?= $a['action'] ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['action']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Role</label>
                            <select name="role" class="form-control">
                                <option value="">All Roles</option>
                                <option value="guidance" <?= $filterRole==='guidance'?'selected':'' ?>>Guidance</option>
                                <option value="guard"    <?= $filterRole==='guard'   ?'selected':'' ?>>Guard</option>
                                <option value="student"  <?= $filterRole==='student' ?'selected':'' ?>>Student</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>From Date</label>
                            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($filterFrom) ?>">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>To Date</label>
                            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($filterTo) ?>">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name or details..." value="<?= htmlspecialchars($filterSearch) ?>">
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">🔍 Filter</button>
                        <a href="<?= BASE_URL ?>guidance/audit_log.php" class="btn btn-outline">✕ Clear</a>
                    </div>
                </form>
            </div>

            <!-- Stats -->
            <div class="stats-grid" style="grid-template-columns:repeat(3,1fr); margin-bottom:1.4rem;">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e8f0fe;">📋</div>
                    <div class="stat-info"><div class="stat-num"><?= $total ?></div><div class="stat-label">Log Entries</div></div>
                </div>
                <div class="stat-card gold">
                    <div class="stat-icon" style="background:#fef3c7;">👥</div>
                    <div class="stat-info">
                        <div class="stat-num"><?= count(array_filter($logs, fn($l) => $l['action'] === 'LOGIN')) ?></div>
                        <div class="stat-label">Logins</div>
                    </div>
                </div>
                <div class="stat-card accent">
                    <div class="stat-icon" style="background:#fee2e2;">🚨</div>
                    <div class="stat-info">
                        <div class="stat-num"><?= count(array_filter($logs, fn($l) => $l['action'] === 'RECORD_VIOLATION')) ?></div>
                        <div class="stat-label">Violations Recorded</div>
                    </div>
                </div>
            </div>

            <!-- Log Table -->
            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                    <div class="card-title" style="margin:0; border:none; padding:0;">
                        Log Entries <span style="font-size:.8rem; font-weight:600; color:var(--muted); margin-left:6px;">(<?= $total ?> results)</span>
                    </div>
                    <input type="text" id="logSearch" class="form-control" placeholder="🔍 Quick search..."
                           oninput="searchLog()" style="max-width:220px; margin:0;">
                </div>
                <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

                <?php if (empty($logs)): ?>
                    <div class="empty-state"><div class="empty-icon">📭</div><p>No log entries found.</p></div>
                <?php else: ?>
                <div class="table-wrap">
                    <table id="logTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date & Time</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $i => $log):
                                $ac = $actionColors[$log['action']] ?? ['bg'=>'#f1f5f9','color'=>'#64748b'];
                            ?>
                            <tr class="log-row">
                                <td><?= $i + 1 ?></td>
                                <td style="white-space:nowrap;">
                                    <div style="font-weight:600;"><?= date('M d, Y', strtotime($log['created_at'])) ?></div>
                                    <div style="font-size:.74rem; color:var(--muted);"><?= date('h:i:s A', strtotime($log['created_at'])) ?></div>
                                </td>
                                <td style="font-weight:700;"><?= htmlspecialchars($log['user_name']) ?></td>
                                <td>
                                    <span class="badge" style="
                                        <?= $log['user_role']==='guidance' ? 'background:#ede9fe;color:#5b21b6;' : '' ?>
                                        <?= $log['user_role']==='guard'    ? 'background:#fef3c7;color:#92400e;' : '' ?>
                                        <?= $log['user_role']==='student'  ? 'background:#dbeafe;color:#1d4ed8;' : '' ?>
                                    "><?= ucfirst($log['user_role']) ?></span>
                                </td>
                                <td>
                                    <span class="action-badge" style="background:<?= $ac['bg'] ?>;color:<?= $ac['color'] ?>;">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="log-details" title="<?= htmlspecialchars($log['details'] ?? '') ?>">
                                        <?= htmlspecialchars($log['details'] ?? '—') ?>
                                    </div>
                                </td>
                                <td style="font-size:.78rem; color:var(--muted); font-family:monospace;">
                                    <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p id="noLogResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</div>

<script>
function searchLog() {
    const q = document.getElementById('logSearch').value.toLowerCase();
    let vis = 0;
    document.querySelectorAll('#logTable tbody tr').forEach(r => {
        const show = r.innerText.toLowerCase().includes(q);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('noLogResults').style.display = vis === 0 ? 'block' : 'none';
}
</script>
</body>
</html>