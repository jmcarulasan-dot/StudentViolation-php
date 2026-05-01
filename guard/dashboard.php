<?php
require_once '../includes/config.php';
requireLogin('guard');

$success = '';
$error   = '';
$foundStudent = null;

// Quick student lookup via GET
if (isset($_GET['lookup']) && $_GET['lookup']) {
    $sno = strtoupper(trim($_GET['lookup']));
    $stmt = $conn->prepare("
        SELECT s.*,
               COUNT(v.id)                              AS vcount,
               SUM(v.status='pending')                  AS vpending,
               SUM(v.appeal_status='pending')           AS vappeal_pending,
               SUM(v.appeal_status='approved')          AS vappeal_approved
        FROM students s
        LEFT JOIN violations v ON v.student_id = s.id
        WHERE s.student_no = ?
        GROUP BY s.id
    ");
    $stmt->bind_param("s", $sno);
    $stmt->execute();
    $foundStudent = $stmt->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no     = strtoupper(trim($_POST['student_no'] ?? ''));
    $violation_type = trim($_POST['violation_type'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $date_recorded  = $_POST['date_recorded'] ?? date('Y-m-d');

    if ($student_no && $violation_type && $date_recorded) {
        $stmt = $conn->prepare("SELECT id FROM students WHERE student_no = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();

        if ($found) {
            $stmt = $conn->prepare("INSERT INTO violations (student_id, violation_type, description, date_recorded, recorded_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param("isssi", $found['id'], $violation_type, $description, $date_recorded, $_SESSION['user_id']);
            $stmt->execute()
                ? $success = "Violation recorded successfully for student <strong>{$student_no}</strong>!"
                : $error   = "Failed to record violation.";
        } else {
            $error = "Student No. <strong>{$student_no}</strong> not found in the system.";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// All violations (with appeal columns)
$allViolations = $conn->query("
    SELECT v.*, s.name AS student_name, s.student_no, u.name AS recorded_by_name
    FROM violations v
    JOIN students s ON v.student_id = s.id
    JOIN users u ON v.recorded_by = u.id
    ORDER BY v.date_recorded DESC, v.id DESC
")->fetch_all(MYSQLI_ASSOC);

$total    = count($allViolations);
$pending  = count(array_filter($allViolations, fn($r) => $r['status'] === 'pending'));
$resolved = $total - $pending;

// My recordings today
$myTodayRows = array_filter($allViolations, fn($r) =>
    $r['recorded_by_name'] === $_SESSION['name'] &&
    date('Y-m-d', strtotime($r['date_recorded'])) === date('Y-m-d')
);
$myToday = count($myTodayRows);

// My recordings this week
$myWeek = count(array_filter($allViolations, fn($r) =>
    $r['recorded_by_name'] === $_SESSION['name'] &&
    strtotime($r['date_recorded']) >= strtotime('monday this week')
));

$students = $conn->query("SELECT student_no, name FROM students ORDER BY name");

// Monthly trend
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $monthlyData[$label] = 0;
}
foreach ($allViolations as $r) {
    $label = date('M Y', strtotime($r['date_recorded']));
    if (isset($monthlyData[$label])) $monthlyData[$label]++;
}

// Type breakdown
$typeData = [];
foreach ($allViolations as $r) {
    $t = $r['violation_type'];
    $typeData[$t] = ($typeData[$t] ?? 0) + 1;
}
arsort($typeData);

// Active tab
$activeTab = 'record';
if ($success || $error) $activeTab = 'record';
if (isset($_GET['tab'])) $activeTab = $_GET['tab'];
if (isset($_GET['lookup'])) $activeTab = 'lookup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Guard Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        .lookup-result {
            border-radius: var(--radius); padding: 1rem 1.2rem;
            background: #eff6ff; border: 1.5px solid #bfdbfe;
            margin-top: .8rem; display: flex; align-items: center;
            gap: 1rem; flex-wrap: wrap;
        }
        .lookup-result.not-found { background:#fef2f2; border-color:#fecaca; }
        .lookup-avatar {
            width: 52px; height: 52px; border-radius: 50%;
            background: #1a3a5c; color: white; font-size: 1.1rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
        }
        .lookup-avatar img { width:100%; height:100%; object-fit:cover; }
        .lookup-info strong { font-size: .95rem; font-weight: 800; display: block; }
        .lookup-info span   { font-size: .78rem; color: var(--muted); }
        .lookup-badges      { display: flex; gap: 6px; margin-top: 5px; flex-wrap: wrap; }

        /* Appeal warning banner */
        .appeal-warning {
            background: #fffbeb; border: 1.5px solid #fcd34d;
            border-radius: 9px; padding: .65rem 1rem;
            font-size: .82rem; font-weight: 600; color: #92400e;
            display: flex; align-items: center; gap: 7px;
            margin-top: .7rem;
        }

        .today-entry {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 0; border-bottom: 1px solid var(--border);
        }
        .today-entry:last-child { border-bottom: none; }
        .today-dot { width:8px; height:8px; border-radius:50%; background:var(--accent); flex-shrink:0; }

        /* Appeal badge */
        .appeal-badge {
            display:inline-block; padding:2px 8px; border-radius:20px;
            font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
        }
        .appeal-none     { background:#f1f5f9; color:#64748b; }
        .appeal-pending  { background:#fef3c7; color:#92400e; }
        .appeal-approved { background:#d1fae5; color:#065f46; }
        .appeal-rejected { background:#fee2e2; color:#991b1b; }

        .confirm-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.5); z-index: 999;
            align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }
        .confirm-overlay.active { display: flex; }
        .confirm-box {
            background: white; border-radius: 16px; padding: 2rem;
            max-width: 420px; width: 90%;
            box-shadow: 0 24px 60px rgba(0,0,0,.25);
        }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- Confirm Submit Modal -->
<div class="confirm-overlay" id="confirmModal">
    <div class="confirm-box">
        <div style="font-size:2rem; margin-bottom:.6rem;">🚨</div>
        <h3 style="font-size:1rem; font-weight:800; color:var(--primary); margin-bottom:.4rem;">Confirm Violation Entry</h3>
        <p style="font-size:.86rem; color:var(--muted); margin-bottom:1.2rem;" id="confirmText">Are you sure you want to record this violation?</p>
        <div style="background:var(--bg); border-radius:9px; padding:.9rem 1rem; margin-bottom:1.2rem; font-size:.85rem;" id="confirmDetails"></div>
        <div style="display:flex; gap:8px; justify-content:flex-end;">
            <button onclick="closeConfirm()" class="btn btn-outline">Cancel</button>
            <button onclick="submitViolation()" class="btn btn-accent">✅ Yes, Record It</button>
        </div>
    </div>
</div>

<div class="page-wrapper">
    <div class="page-header">
        <h2>Guard Dashboard</h2>
        <p>Logged in as <strong><?= htmlspecialchars($_SESSION['name']) ?></strong> · <?= date('l, F d, Y') ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f0fe;">📋</div>
            <div class="stat-info"><div class="stat-num"><?= $total ?></div><div class="stat-label">Total Violations</div></div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon" style="background:#fee2e2;">⚠️</div>
            <div class="stat-info"><div class="stat-num"><?= $pending ?></div><div class="stat-label">Pending</div></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon" style="background:#d1fae5;">✅</div>
            <div class="stat-info"><div class="stat-num"><?= $resolved ?></div><div class="stat-label">Resolved</div></div>
        </div>
        <div class="stat-card gold">
            <div class="stat-icon" style="background:#fef3c7;">📝</div>
            <div class="stat-info"><div class="stat-num"><?= $myToday ?></div><div class="stat-label">My Entries Today</div></div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon" style="background:#ede9fe;">📆</div>
            <div class="stat-info"><div class="stat-num"><?= $myWeek ?></div><div class="stat-label">My Entries This Week</div></div>
        </div>
    </div>

    <!-- Charts -->
    <?php if ($total > 0): ?>
    <div class="g2e" style="margin-bottom:1.4rem;">
        <div class="chart-card">
            <div class="chart-title"><span class="cdot" style="background:#1a3a5c;"></span>Violations Trend — Last 6 Months</div>
            <div class="cwrap"><canvas id="trendChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-title"><span class="cdot" style="background:#f0a500;"></span>By Violation Type</div>
            <div class="cwrap"><canvas id="typeChart"></canvas></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn" id="btn-record" onclick="switchTab('record',this)">📋 Record Violation</button>
        <button class="tab-btn" id="btn-lookup" onclick="switchTab('lookup',this)">🔍 Student Lookup</button>
        <button class="tab-btn" id="btn-today"  onclick="switchTab('today',this)">
            📅 Today's Log
            <?php if ($myToday > 0): ?>
            <span style="background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:.68rem;margin-left:4px;"><?= $myToday ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn" id="btn-all"    onclick="switchTab('all',this)">
            📂 All Violations
            <span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 7px;font-size:.68rem;margin-left:4px;"><?= $total ?></span>
        </button>
    </div>

    <!-- ══ TAB: RECORD ══ -->
    <div id="tab-record" class="tab-content">
        <div class="card">
            <div class="card-title">📋 Record a Violation</div>

            <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-error">❌ <?= $error ?></div><?php endif; ?>

            <form id="violationForm" onsubmit="showConfirm(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Student No. <span style="color:var(--accent)">*</span></label>
                        <input type="text" name="student_no" id="sno_input" class="form-control"
                               list="students-list"
                               placeholder="e.g. C26-01-0001-MAN121"
                               oninput="this.value=this.value.toUpperCase()"
                               required>
                        <datalist id="students-list">
                            <?php while ($s = $students->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($s['student_no']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Violation Type <span style="color:var(--accent)">*</span></label>
                        <select name="violation_type" id="vtype_input" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <option>Late</option>
                            <option>Cutting Class</option>
                            <option>Improper Uniform</option>
                            <option>Disruptive Behavior</option>
                            <option>Vandalism</option>
                            <option>Prohibited Items</option>
                            <option>Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Date <span style="color:var(--accent)">*</span></label>
                        <input type="date" name="date_recorded" id="date_input" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                        <input type="text" name="description" id="desc_input" class="form-control" placeholder="Additional details...">
                    </div>
                </div>
                <button type="submit" class="btn btn-accent">🚨 Record Violation</button>
            </form>

            <!-- Hidden real form -->
            <form id="realForm" method="POST" style="display:none;">
                <input type="hidden" name="student_no"     id="h_sno">
                <input type="hidden" name="violation_type" id="h_vtype">
                <input type="hidden" name="date_recorded"  id="h_date">
                <input type="hidden" name="description"    id="h_desc">
            </form>
        </div>
    </div>

    <!-- ══ TAB: STUDENT LOOKUP ══ -->
    <div id="tab-lookup" class="tab-content">
        <div class="card">
            <div class="card-title">🔍 Student Quick Lookup</div>
            <p style="font-size:.85rem; color:var(--muted); margin-bottom:1rem;">
                Look up a student by their ID number to see their violation history and appeal statuses before recording.
            </p>
            <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="tab" value="lookup">
                <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                    <label>Student No.</label>
                    <input type="text" name="lookup" class="form-control"
                           placeholder="e.g. C26-01-0001-MAN121"
                           value="<?= htmlspecialchars($_GET['lookup'] ?? '') ?>"
                           oninput="this.value=this.value.toUpperCase()">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:1.1rem;">Search</button>
            </form>

            <?php if (isset($_GET['lookup']) && $_GET['lookup']): ?>
                <?php if ($foundStudent): ?>

                <!-- Student info row -->
                <div class="lookup-result">
                    <!-- Profile photo or default avatar -->
                    <div class="lookup-avatar">
                        <?php if (!empty($foundStudent['profile_photo'])): ?>
                            <img src="<?= BASE_URL ?>uploads/profile/<?= htmlspecialchars($foundStudent['profile_photo']) ?>" alt="Photo">
                        <?php else: ?>
                            🎓
                        <?php endif; ?>
                    </div>

                    <div class="lookup-info" style="flex:1;">
                        <strong><?= htmlspecialchars($foundStudent['name']) ?></strong>
                        <span><?= htmlspecialchars($foundStudent['student_no']) ?> · <?= htmlspecialchars($foundStudent['course']) ?> Year <?= $foundStudent['year_level'] ?></span>
                        <div class="lookup-badges">
                            <span class="badge" style="background:#e8f0fe; color:#1d4ed8;">
                                <?= $foundStudent['vcount'] ?> total violation<?= $foundStudent['vcount'] != 1 ? 's' : '' ?>
                            </span>
                            <?php if ($foundStudent['vpending'] > 0): ?>
                                <span class="badge badge-pending"><?= $foundStudent['vpending'] ?> pending</span>
                            <?php else: ?>
                                <span class="badge badge-resolved">All clear</span>
                            <?php endif; ?>
                            <?php if ($foundStudent['vappeal_pending'] > 0): ?>
                                <span class="badge" style="background:#fef3c7; color:#92400e;">
                                    ⚖️ <?= $foundStudent['vappeal_pending'] ?> appeal<?= $foundStudent['vappeal_pending'] > 1 ? 's' : '' ?> pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button onclick="prefillRecord('<?= htmlspecialchars($foundStudent['student_no']) ?>')"
                            class="btn btn-accent btn-sm">
                        🚨 Record Violation
                    </button>
                </div>

                <!-- Appeal warning — guard heads-up -->
                <?php if ($foundStudent['vappeal_pending'] > 0): ?>
                <div class="appeal-warning">
                    ⚖️ This student has <strong><?= $foundStudent['vappeal_pending'] ?> pending appeal<?= $foundStudent['vappeal_pending'] > 1 ? 's' : '' ?></strong>
                    under guidance review. Consider this before recording a new violation.
                </div>
                <?php endif; ?>

                <!-- Violation history with appeal status -->
                <?php
                $sViolations = array_filter($allViolations, fn($v) => $v['student_no'] === $foundStudent['student_no']);
                ?>
                <?php if (!empty($sViolations)): ?>
                <div style="margin-top:1.2rem;">
                    <div class="card-title" style="font-size:.82rem;">Violation History</div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Violation</th>
                                    <th>Date</th>
                                    <th>Recorded By</th>
                                    <th>Status</th>
                                    <th>Appeal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sViolations as $sv):
                                    $as = $sv['appeal_status'] ?? 'none';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($sv['violation_type']) ?></strong>
                                        <?php if ($sv['description']): ?>
                                        <div style="font-size:.76rem; color:var(--muted);"><?= htmlspecialchars($sv['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($sv['date_recorded'])) ?></td>
                                    <td style="font-size:.82rem; color:var(--muted);"><?= htmlspecialchars($sv['recorded_by_name']) ?></td>
                                    <td><span class="badge badge-<?= $sv['status'] ?>"><?= ucfirst($sv['status']) ?></span></td>
                                    <td>
                                        <span class="appeal-badge appeal-<?= $as ?>">
                                            <?= $as === 'none' ? 'No appeal' : ucfirst($as) ?>
                                        </span>
                                        <?php if (!empty($sv['appeal_remarks']) && $as !== 'none'): ?>
                                        <div style="font-size:.72rem; color:var(--muted); margin-top:2px;">
                                            <?= htmlspecialchars(mb_substr($sv['appeal_remarks'], 0, 50)) ?><?= strlen($sv['appeal_remarks']) > 50 ? '…' : '' ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="lookup-result not-found">
                    <div class="lookup-avatar" style="background:#e84545;">❌</div>
                    <div>
                        <strong style="font-size:.9rem; color:#991b1b;">Student not found</strong><br>
                        <span style="font-size:.8rem; color:#c53030;">No student with ID <strong><?= htmlspecialchars($_GET['lookup']) ?></strong> exists in the system.</span>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB: TODAY'S LOG ══ -->
    <div id="tab-today" class="tab-content">
        <div class="card">
            <div class="card-title">📅 My Entries Today — <?= date('F d, Y') ?></div>
            <?php if (empty($myTodayRows)): ?>
                <div class="empty-state">
                    <div class="empty-icon">☀️</div>
                    <p>No violations recorded by you today.</p>
                </div>
            <?php else: ?>
                <?php foreach ($myTodayRows as $tr): ?>
                <div class="today-entry">
                    <div class="today-dot"></div>
                    <div style="flex:1;">
                        <div style="font-size:.88rem; font-weight:700;"><?= htmlspecialchars($tr['student_name']) ?>
                            <code style="font-size:.76rem; color:var(--primary); font-weight:400; margin-left:6px;"><?= htmlspecialchars($tr['student_no']) ?></code>
                        </div>
                        <div style="font-size:.78rem; color:var(--muted);">
                            <?= htmlspecialchars($tr['violation_type']) ?>
                            <?php if ($tr['description']): ?> · <?= htmlspecialchars($tr['description']) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge badge-<?= $tr['status'] ?>"><?= ucfirst($tr['status']) ?></span>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border); font-size:.82rem; color:var(--muted);">
                    <?= $myToday ?> entr<?= $myToday != 1 ? 'ies' : 'y' ?> recorded today.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB: ALL VIOLATIONS ══ -->
    <div id="tab-all" class="tab-content">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                <div class="card-title" style="margin:0; border:none; padding:0;">All Violations</div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <select id="filterStatus" onchange="filterTable()" class="form-control" style="max-width:140px; margin:0; padding:8px 12px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="resolved">Resolved</option>
                    </select>
                    <input type="text" id="searchInput" class="form-control"
                           placeholder="🔍 Search..." oninput="filterTable()"
                           style="max-width:200px; margin:0;">
                </div>
            </div>
            <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

            <?php if (empty($allViolations)): ?>
                <div class="empty-state"><div class="empty-icon">📂</div><p>No violations recorded yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table id="violationsTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Student No.</th><th>Student Name</th>
                            <th>Violation</th><th>Description</th><th>Date</th>
                            <th>Recorded By</th><th>Status</th><th>Appeal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allViolations as $i => $v):
                            $as = $v['appeal_status'] ?? 'none';
                        ?>
                        <tr data-status="<?= $v['status'] ?>">
                            <td><?= $i + 1 ?></td>
                            <td><code style="font-size:.8rem; color:var(--primary);"><?= htmlspecialchars($v['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($v['student_name']) ?></td>
                            <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                            <td style="color:var(--muted); font-size:.83rem;"><?= htmlspecialchars($v['description'] ?? '—') ?></td>
                            <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                            <td style="font-size:.82rem; color:var(--muted);"><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                            <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                            <td>
                                <span class="appeal-badge appeal-<?= $as ?>">
                                    <?= $as === 'none' ? '—' : ucfirst($as) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="noResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
<?php if ($total > 0): ?>
Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

new Chart(document.getElementById('trendChart'), {
    type:'line',
    data:{ labels:<?= json_encode(array_keys($monthlyData)) ?>, datasets:[{ label:'Violations', data:<?= json_encode(array_values($monthlyData)) ?>, borderColor:'#1a3a5c', backgroundColor:'rgba(26,58,92,0.08)', borderWidth:2.5, pointBackgroundColor:'#1a3a5c', pointRadius:5, fill:true, tension:0.4 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, x:{grid:{display:false}} } }
});

new Chart(document.getElementById('typeChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($typeData)) ?>, datasets:[{ data:<?= json_encode(array_values($typeData)) ?>, backgroundColor:['#1a3a5c','#e84545','#f0a500','#8b5cf6','#2ecc71','#3b82f6','#14b8a6'], borderRadius:5, borderSkipped:false }] },
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{ x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, y:{grid:{display:false}} } }
});
<?php endif; ?>

// ── Tabs ──────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
}
switchTab('<?= $activeTab ?>', document.getElementById('btn-<?= $activeTab ?>'));

// ── Confirm modal ─────────────────────────────────────────
function showConfirm(e) {
    e.preventDefault();
    const sno   = document.getElementById('sno_input').value.trim();
    const vtype = document.getElementById('vtype_input').value;
    const date  = document.getElementById('date_input').value;
    const desc  = document.getElementById('desc_input').value;
    if (!sno || !vtype || !date) return;

    document.getElementById('confirmDetails').innerHTML =
        `<div style="display:grid; grid-template-columns:auto 1fr; gap:4px 12px; font-size:.84rem;">
            <span style="color:var(--muted); font-weight:600;">Student No.</span><span><strong>${sno}</strong></span>
            <span style="color:var(--muted); font-weight:600;">Violation</span><span><strong>${vtype}</strong></span>
            <span style="color:var(--muted); font-weight:600;">Date</span><span>${date}</span>
            ${desc ? `<span style="color:var(--muted); font-weight:600;">Note</span><span>${desc}</span>` : ''}
        </div>`;

    document.getElementById('h_sno').value   = sno;
    document.getElementById('h_vtype').value = vtype;
    document.getElementById('h_date').value  = date;
    document.getElementById('h_desc').value  = desc;
    document.getElementById('confirmModal').classList.add('active');
}
function closeConfirm() { document.getElementById('confirmModal').classList.remove('active'); }
function submitViolation() { document.getElementById('realForm').submit(); }
document.getElementById('confirmModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeConfirm(); });

// ── Pre-fill record from lookup ───────────────────────────
function prefillRecord(sno) {
    switchTab('record', document.getElementById('btn-record'));
    document.getElementById('sno_input').value = sno;
}

// ── Filter all violations table ───────────────────────────
function filterTable() {
    const q      = document.getElementById('searchInput').value.toLowerCase();
    const status = document.getElementById('filterStatus').value;
    let vis = 0;
    document.querySelectorAll('#violationsTable tbody tr').forEach(r => {
        const show = r.innerText.toLowerCase().includes(q) && (!status || r.dataset.status === status);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('noResults').style.display = vis === 0 ? 'block' : 'none';
}
</script>
</body>
</html>