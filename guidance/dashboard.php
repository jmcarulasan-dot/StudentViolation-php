<?php
require_once '../includes/config.php';
requireLogin('guidance');

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $student_no = trim($_POST['student_no']);
        $name       = trim($_POST['name']);
        $course     = trim($_POST['course']);
        $year_level = intval($_POST['year_level']);
        $username   = trim($_POST['username']);
        $password   = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO students (student_no, name, course, year_level) VALUES (?,?,?,?)");
        $stmt->bind_param("sssi", $student_no, $name, $course, $year_level);
        if ($stmt->execute()) {
            $new_student_id = $conn->insert_id;
            $stmt2 = $conn->prepare("INSERT INTO users (name, username, password, role, student_id) VALUES (?,?,?,'student',?)");
            $stmt2->bind_param("sssi", $name, $username, $password, $new_student_id);
            $stmt2->execute() ? $success = "Student added successfully!" : $error = "Student added but failed to create login: " . $conn->error;
        } else {
            $error = "Failed to add student. Student No. may already exist.";
        }
    }

    if ($action === 'update_status') {
        $vid    = intval($_POST['violation_id']);
        $status = $_POST['status'];
        $stmt   = $conn->prepare("UPDATE violations SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $vid);
        $stmt->execute();
        $success = "Violation status updated!";
    }

    if ($action === 'edit_violation') {
        $vid            = intval($_POST['violation_id']);
        $violation_type = trim($_POST['violation_type']);
        $description    = trim($_POST['description']);
        $date_recorded  = $_POST['date_recorded'];
        $status         = $_POST['status'];

        $stmt = $conn->prepare("UPDATE violations SET violation_type = ?, description = ?, date_recorded = ?, status = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $violation_type, $description, $date_recorded, $status, $vid);
        $stmt->execute() ? $success = "Violation updated successfully!" : $error = "Failed to update violation.";
    }

    if ($action === 'delete_violation') {
        $vid  = intval($_POST['violation_id']);
        $stmt = $conn->prepare("DELETE FROM violations WHERE id = ?");
        $stmt->bind_param("i", $vid);
        $stmt->execute();
        $success = "Violation deleted.";
    }

    if ($action === 'delete_student') {
        $sid  = intval($_POST['student_id']);
        $stmt = $conn->prepare("DELETE FROM violations WHERE student_id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM users WHERE student_id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param("i", $sid);
        $stmt->execute();
        $success = "Student and all related records deleted.";
    }
}

// ── Core data ──────────────────────────────────────────────
$students   = $conn->query("SELECT * FROM students ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$violations = $conn->query("
    SELECT v.*, s.name AS student_name, s.student_no, s.course, u.name AS recorded_by_name
    FROM violations v
    JOIN students s ON v.student_id = s.id
    JOIN users u ON v.recorded_by = u.id
    ORDER BY v.date_recorded DESC
")->fetch_all(MYSQLI_ASSOC);

$totalStudents   = count($students);
$totalViolations = count($violations);
$pendingCount    = count(array_filter($violations, fn($v) => $v['status'] === 'pending'));
$resolvedCount   = $totalViolations - $pendingCount;
$recentCount     = count(array_filter($violations, fn($v) => strtotime($v['date_recorded']) >= strtotime('-7 days')));

// ── Monthly trend (last 6 months) ─────────────────────────
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $monthlyData[$label] = 0;
}
foreach ($violations as $v) {
    $label = date('M Y', strtotime($v['date_recorded']));
    if (isset($monthlyData[$label])) $monthlyData[$label]++;
}

// ── By type ────────────────────────────────────────────────
$typeData = [];
foreach ($violations as $v) {
    $t = $v['violation_type'];
    $typeData[$t] = ($typeData[$t] ?? 0) + 1;
}
arsort($typeData);

// ── By course ──────────────────────────────────────────────
$courseResult = $conn->query("
    SELECT s.course, COUNT(v.id) AS cnt
    FROM violations v JOIN students s ON v.student_id = s.id
    GROUP BY s.course ORDER BY cnt DESC
");
$courseData = [];
while ($row = $courseResult->fetch_assoc()) {
    $courseData[$row['course']] = (int)$row['cnt'];
}

// ── Stacked monthly pending vs resolved ───────────────────
$statusMonthly = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $statusMonthly[$label] = ['pending' => 0, 'resolved' => 0];
}
foreach ($violations as $v) {
    $label = date('M Y', strtotime($v['date_recorded']));
    if (isset($statusMonthly[$label])) $statusMonthly[$label][$v['status']]++;
}

// ── Top 5 students ─────────────────────────────────────────
$topStudents = $conn->query("
    SELECT s.name, s.student_no, COUNT(v.id) AS cnt
    FROM violations v JOIN students s ON v.student_id = s.id
    GROUP BY s.id ORDER BY cnt DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Violation count per student (for students tab) ─────────
$vcounts = [];
foreach ($violations as $v) {
    $vcounts[$v['student_no']] = ($vcounts[$v['student_no']] ?? 0) + 1;
}

// ── Restore active tab after POST ─────────────────────────
$activeTab = 'overview';
if ($_POST) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_student')    $activeTab = 'add-student';
    elseif ($act === 'delete_student') $activeTab = 'students';
    elseif (in_array($act, ['update_status','edit_violation','delete_violation'])) $activeTab = 'violations';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Guidance Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.45); z-index:999;
            align-items:center; justify-content:center;
            backdrop-filter:blur(2px);
        }
        .modal-overlay.active { display:flex; }
        .action-form { display:inline; }

        /* ── Tabs ── */
        .tabs {
            display:flex; gap:0; margin-bottom:1.5rem;
            border-bottom:2px solid var(--border);
            overflow-x:auto;
        }
        .tab-btn {
            padding:11px 20px; border:none;
            border-bottom:3px solid transparent;
            background:transparent; font-family:var(--font);
            font-weight:700; font-size:0.84rem;
            cursor:pointer; color:var(--muted);
            transition:all 0.2s; white-space:nowrap;
            margin-bottom:-2px;
        }
        .tab-btn:hover:not(.active) { color:var(--primary); background:rgba(26,58,92,0.04); }
        .tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); background:rgba(26,58,92,0.04); }
        .tab-content { display:none; }
        .tab-content.active { display:block; }

        /* ── Chart cards ── */
        .chart-card {
            background:var(--white); border-radius:var(--radius);
            box-shadow:var(--card-shadow); padding:1.4rem;
            border:1px solid var(--border);
        }
        .chart-title {
            font-size:0.78rem; font-weight:700; color:var(--primary);
            text-transform:uppercase; letter-spacing:0.5px;
            margin-bottom:1rem; display:flex; align-items:center; gap:6px;
        }
        .cdot { width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0; }
        .cwrap { position:relative; height:220px; }
        .cwrap.tall { height:260px; }

        .g2 { display:grid; grid-template-columns:2fr 1fr; gap:1rem; margin-bottom:1rem; }
        .g3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem; }
        .g1 { margin-bottom:1rem; }

        /* ── Mini stat cards ── */
        .mini-row {
            display:grid; grid-template-columns:repeat(5,1fr);
            gap:.75rem; margin-bottom:1rem;
        }
        .mini-card {
            background:var(--white); border-radius:10px;
            border:1px solid var(--border); box-shadow:var(--card-shadow);
            padding:.9rem 1rem; display:flex; align-items:center; gap:.7rem;
        }
        .mini-icon { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
        .mini-num  { font-size:1.55rem; font-weight:800; color:var(--primary); line-height:1; letter-spacing:-1px; }
        .mini-lbl  { font-size:0.66rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-top:2px; }
        .mini-card.acc  .mini-num { color:var(--accent); }
        .mini-card.grn  .mini-num { color:var(--success); }
        .mini-card.gld  .mini-num { color:var(--gold); }
        .mini-card.pur  .mini-num { color:#8b5cf6; }

        /* ── Top students ── */
        .top-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border); }
        .top-item:last-child { border-bottom:none; }
        .top-rank { width:26px; height:26px; border-radius:50%; background:var(--primary); color:white; font-size:.7rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .top-rank.gold   { background:#f0a500; }
        .top-rank.silver { background:#94a3b8; }
        .top-rank.bronze { background:#b45309; }
        .top-info { flex:1; min-width:0; }
        .top-info strong { display:block; font-size:.83rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .top-info span   { font-size:.7rem; color:var(--muted); }
        .bar-mini { height:5px; border-radius:3px; background:var(--accent); margin-top:3px; }
        .top-cnt  { font-size:.95rem; font-weight:800; color:var(--accent); min-width:24px; text-align:right; }

        @media(max-width:960px) { .g2,.g3 { grid-template-columns:1fr; } .mini-row { grid-template-columns:repeat(3,1fr); } }
        @media(max-width:600px) { .mini-row { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- Edit Violation Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-title">✏️ Edit Violation</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_violation">
            <input type="hidden" name="violation_id" id="edit_violation_id">
            <div class="form-group">
                <label>Violation Type <span style="color:var(--accent)">*</span></label>
                <select name="violation_type" id="edit_violation_type" class="form-control" required>
                    <option>Late</option><option>Cutting Class</option>
                    <option>Improper Uniform</option><option>Disruptive Behavior</option>
                    <option>Vandalism</option><option>Prohibited Items</option><option>Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" id="edit_description" class="form-control">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date <span style="color:var(--accent)">*</span></label>
                    <input type="date" name="date_recorded" id="edit_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Status <span style="color:var(--accent)">*</span></label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <option value="pending">Pending</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="page-wrapper">
    <div class="page-header">
        <h2>Guidance Admin Dashboard</h2>
        <p>Full control over students, violation records, and analytics.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- ══ TABS ══ -->
    <div class="tabs">
        <button class="tab-btn" id="btn-overview"    onclick="switchTab('overview',this)">📊 Overview</button>
        <button class="tab-btn" id="btn-analytics"   onclick="switchTab('analytics',this)">📈 Analytics</button>
        <button class="tab-btn" id="btn-violations"  onclick="switchTab('violations',this)">📂 Violations <span style="background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:.72rem;margin-left:4px;"><?= $totalViolations ?></span></button>
        <button class="tab-btn" id="btn-students"    onclick="switchTab('students',this)">👥 Students <span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 7px;font-size:.72rem;margin-left:4px;"><?= $totalStudents ?></span></button>
        <button class="tab-btn" id="btn-add-student" onclick="switchTab('add-student',this)">➕ Add Student</button>
    </div>

    <!-- ══ TAB: OVERVIEW ══ -->
    <div id="tab-overview" class="tab-content">
        <!-- Mini stat cards -->
        <div class="mini-row">
            <div class="mini-card">
                <div class="mini-icon" style="background:#e8f0fe;">👥</div>
                <div><div class="mini-num"><?= $totalStudents ?></div><div class="mini-lbl">Students</div></div>
            </div>
            <div class="mini-card gld">
                <div class="mini-icon" style="background:#fef3c7;">📋</div>
                <div><div class="mini-num"><?= $totalViolations ?></div><div class="mini-lbl">Total Violations</div></div>
            </div>
            <div class="mini-card acc">
                <div class="mini-icon" style="background:#fee2e2;">⚠️</div>
                <div><div class="mini-num"><?= $pendingCount ?></div><div class="mini-lbl">Pending</div></div>
            </div>
            <div class="mini-card grn">
                <div class="mini-icon" style="background:#d1fae5;">✅</div>
                <div><div class="mini-num"><?= $resolvedCount ?></div><div class="mini-lbl">Resolved</div></div>
            </div>
            <div class="mini-card pur">
                <div class="mini-icon" style="background:#ede9fe;">🆕</div>
                <div><div class="mini-num"><?= $recentCount ?></div><div class="mini-lbl">Last 7 Days</div></div>
            </div>
        </div>

        <!-- Line + Doughnut -->
        <div class="g2">
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--primary);"></span>Violations Trend — Last 6 Months</div>
                <div class="cwrap"><canvas id="lineChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--accent);"></span>Pending vs Resolved</div>
                <div class="cwrap"><canvas id="doughnutChart"></canvas></div>
            </div>
        </div>

        <!-- Top 5 + Recent -->
        <div class="g2">
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--accent);"></span>Top 5 — Most Violations</div>
                <?php if (empty($topStudents)): ?>
                    <p style="color:var(--muted); font-size:.85rem; text-align:center; padding:1rem 0;">No data yet.</p>
                <?php else:
                    $maxCnt = $topStudents[0]['cnt'];
                    $rc = ['gold','silver','bronze','',''];
                ?>
                <?php foreach ($topStudents as $i => $ts): ?>
                <div class="top-item">
                    <div class="top-rank <?= $rc[$i] ?>"><?= $i+1 ?></div>
                    <div class="top-info">
                        <strong><?= htmlspecialchars($ts['name']) ?></strong>
                        <span><?= htmlspecialchars($ts['student_no']) ?></span>
                        <div class="bar-mini" style="width:<?= round(($ts['cnt']/$maxCnt)*100) ?>%;"></div>
                    </div>
                    <div class="top-cnt"><?= $ts['cnt'] ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:#8b5cf6;"></span>5 Most Recent Violations</div>
                <?php if (empty($violations)): ?>
                    <p style="color:var(--muted); font-size:.85rem; text-align:center; padding:1rem 0;">None yet.</p>
                <?php else: ?>
                <?php foreach (array_slice($violations, 0, 5) as $rv): ?>
                <div style="padding:8px 0; border-bottom:1px solid var(--border);">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <div style="font-size:.84rem; font-weight:700;"><?= htmlspecialchars($rv['student_name']) ?></div>
                            <div style="font-size:.74rem; color:var(--muted);"><?= htmlspecialchars($rv['violation_type']) ?> · <?= date('M d, Y', strtotime($rv['date_recorded'])) ?></div>
                        </div>
                        <span class="badge badge-<?= $rv['status'] ?>"><?= ucfirst($rv['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:.8rem;">
                    <button class="btn btn-outline btn-sm" onclick="switchTab('violations', document.getElementById('btn-violations'))">View All →</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ TAB: ANALYTICS ══ -->
    <div id="tab-analytics" class="tab-content">
        <!-- Bar by type + Pie by course -->
        <div class="g2" style="margin-bottom:1rem;">
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--gold);"></span>Violations by Type</div>
                <div class="cwrap tall"><canvas id="barChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:#8b5cf6;"></span>Violations by Course</div>
                <div class="cwrap tall"><canvas id="courseChart"></canvas></div>
            </div>
        </div>

        <!-- Stacked bar full width -->
        <div class="chart-card g1">
            <div class="chart-title"><span class="cdot" style="background:var(--success);"></span>Pending vs Resolved — Monthly Breakdown</div>
            <div class="cwrap tall"><canvas id="stackedChart"></canvas></div>
        </div>

        <!-- Breakdown table -->
        <div class="card">
            <div class="card-title">📋 Violation Type Breakdown</div>
            <?php if (empty($typeData)): ?>
                <div class="empty-state"><div class="empty-icon">📭</div><p>No data yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Violation Type</th><th>Count</th><th>% of Total</th><th style="width:200px;">Visual</th></tr></thead>
                    <tbody>
                        <?php foreach ($typeData as $type => $cnt): $pct = $totalViolations > 0 ? round(($cnt/$totalViolations)*100, 1) : 0; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($type) ?></strong></td>
                            <td><?= $cnt ?></td>
                            <td><?= $pct ?>%</td>
                            <td>
                                <div style="background:var(--border); border-radius:4px; height:8px; overflow:hidden;">
                                    <div style="background:var(--primary); height:100%; width:<?= $pct ?>%; border-radius:4px;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB: VIOLATIONS ══ -->
    <div id="tab-violations" class="tab-content">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                <div class="card-title" style="margin:0; border:none; padding:0;">
                    All Violation Records
                    <span style="font-size:.8rem; font-weight:600; color:var(--muted); margin-left:8px;">(<?= $totalViolations ?> total)</span>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <select id="filterStatus" onchange="filterViolations()" class="form-control" style="max-width:140px; margin:0; padding:8px 12px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="resolved">Resolved</option>
                    </select>
                    <input type="text" id="searchViolations" class="form-control"
                           placeholder="🔍 Search..." oninput="filterViolations()"
                           style="max-width:220px; margin:0;">
                </div>
            </div>
            <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>
            <?php if (empty($violations)): ?>
                <div class="empty-state"><div class="empty-icon">📂</div><p>No violations yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table id="violationsTable">
                    <thead>
                        <tr><th>#</th><th>Student No.</th><th>Student</th><th>Violation</th><th>Date</th><th>Recorded By</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $i => $v): ?>
                        <tr data-status="<?= $v['status'] ?>">
                            <td><?= $i+1 ?></td>
                            <td><code style="font-size:.82rem; color:var(--primary);"><?= htmlspecialchars($v['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($v['student_name']) ?></td>
                            <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                            <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                            <td><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                            <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                            <td style="display:flex; gap:5px; flex-wrap:wrap;">
                                <button class="btn btn-sm btn-outline" onclick="openEdit(<?= $v['id'] ?>,'<?= addslashes($v['violation_type']) ?>','<?= addslashes($v['description'] ?? '') ?>','<?= $v['date_recorded'] ?>','<?= $v['status'] ?>')">Edit</button>
                                <form class="action-form" method="POST">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $v['status'] === 'pending' ? 'resolved' : 'pending' ?>">
                                    <button type="submit" class="btn btn-sm <?= $v['status'] === 'pending' ? 'btn-success' : 'btn-outline' ?>"><?= $v['status'] === 'pending' ? 'Resolve' : 'Reopen' ?></button>
                                </form>
                                <form class="action-form" method="POST" onsubmit="return confirm('Delete this violation?')">
                                    <input type="hidden" name="action" value="delete_violation">
                                    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="noViolationResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB: STUDENTS ══ -->
    <div id="tab-students" class="tab-content">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                <div class="card-title" style="margin:0; border:none; padding:0;">
                    All Students
                    <span style="font-size:.8rem; font-weight:600; color:var(--muted); margin-left:8px;">(<?= $totalStudents ?> enrolled)</span>
                </div>
                <input type="text" id="searchStudents" class="form-control"
                       placeholder="🔍 Search students..." oninput="searchTable('studentsTable','searchStudents','noStudentResults')"
                       style="max-width:240px; margin:0;">
            </div>
            <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>
            <?php if (empty($students)): ?>
                <div class="empty-state"><div class="empty-icon">👥</div><p>No students yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table id="studentsTable">
                    <thead>
                        <tr><th>#</th><th>Student No.</th><th>Name</th><th>Course</th><th>Year Level</th><th>Violations</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $s):
                            $vc = $vcounts[$s['student_no']] ?? 0;
                        ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><code style="font-size:.82rem; color:var(--primary);"><?= htmlspecialchars($s['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($s['name']) ?></td>
                            <td><?= htmlspecialchars($s['course']) ?></td>
                            <td>Year <?= $s['year_level'] ?></td>
                            <td>
                                <?php if ($vc > 0): ?>
                                    <span class="badge" style="background:#fee2e2; color:#991b1b;"><?= $vc ?> violation<?= $vc > 1 ? 's' : '' ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:#d1fae5; color:#065f46;">Clean</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="action-form" method="POST" onsubmit="return confirm('Delete this student and ALL their violations?')">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="noStudentResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB: ADD STUDENT ══ -->
    <div id="tab-add-student" class="tab-content">
        <div class="card">
            <div class="card-title">➕ Add New Student</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_student">
                <div class="form-row">
                    <div class="form-group">
                        <label>Student No. <span style="color:var(--accent)">*</span></label>
                        <input type="text" name="student_no" class="form-control" placeholder="C26-01-0001-MAN121" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name <span style="color:var(--accent)">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Juan dela Cruz" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Course <span style="color:var(--accent)">*</span></label>
                        <select name="course" class="form-control" required>
                            <option value="">-- Select Course --</option>
                            <option>BSIT</option><option>BSCS</option>
                            <option>BSA</option><option>BSBA</option><option>BSHM</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year Level <span style="color:var(--accent)">*</span></label>
                        <select name="year_level" class="form-control" required>
                            <option value="">-- Select Year --</option>
                            <option value="1">Year 1</option><option value="2">Year 2</option>
                            <option value="3">Year 3</option><option value="4">Year 4</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Login Username <span style="color:var(--accent)">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. juan.delacruz" required>
                    </div>
                    <div class="form-group">
                        <label>Login Password <span style="color:var(--accent)">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Set initial password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Student</button>
            </form>
        </div>
    </div>

</div><!-- .page-wrapper -->

<script>
// ── Data ──────────────────────────────────────────────────
const monthlyLabels   = <?= json_encode(array_keys($monthlyData)) ?>;
const monthlyCounts   = <?= json_encode(array_values($monthlyData)) ?>;
const typeLabels      = <?= json_encode(array_keys($typeData)) ?>;
const typeCounts      = <?= json_encode(array_values($typeData)) ?>;
const courseLabels    = <?= json_encode(array_keys($courseData)) ?>;
const courseCounts    = <?= json_encode(array_values($courseData)) ?>;
const stackedPending  = <?= json_encode(array_column(array_values($statusMonthly),'pending')) ?>;
const stackedResolved = <?= json_encode(array_column(array_values($statusMonthly),'resolved')) ?>;
const pendingTotal    = <?= $pendingCount ?>;
const resolvedTotal   = <?= $resolvedCount ?>;

Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

const C = { primary:'#1a3a5c', accent:'#e84545', gold:'#f0a500', green:'#2ecc71', purple:'#8b5cf6', blue:'#3b82f6', teal:'#14b8a6', orange:'#f97316' };
const PAL = Object.values(C);

// 1. Line
new Chart(document.getElementById('lineChart'), {
    type:'line',
    data:{ labels:monthlyLabels, datasets:[{ label:'Violations', data:monthlyCounts, borderColor:C.primary, backgroundColor:'rgba(26,58,92,0.08)', borderWidth:2.5, pointBackgroundColor:C.primary, pointRadius:5, pointHoverRadius:7, fill:true, tension:0.4 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{mode:'index',intersect:false} }, scales:{ y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, x:{grid:{display:false}} } }
});

// 2. Doughnut
new Chart(document.getElementById('doughnutChart'), {
    type:'doughnut',
    data:{ labels:['Pending','Resolved'], datasets:[{ data:[pendingTotal,resolvedTotal], backgroundColor:[C.accent,C.green], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'65%', plugins:{ legend:{ position:'bottom', labels:{padding:16,usePointStyle:true,pointStyleWidth:10} } } }
});

// 3. Bar by type
new Chart(document.getElementById('barChart'), {
    type:'bar',
    data:{ labels:typeLabels, datasets:[{ label:'Count', data:typeCounts, backgroundColor:PAL.slice(0,typeLabels.length), borderRadius:5, borderSkipped:false }] },
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{ x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, y:{grid:{display:false}} } }
});

// 4. Pie by course
new Chart(document.getElementById('courseChart'), {
    type:'pie',
    data:{ labels:courseLabels, datasets:[{ data:courseCounts, backgroundColor:[C.primary,C.purple,C.gold,C.teal,C.orange], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{padding:12,usePointStyle:true,pointStyleWidth:10,font:{size:11}} } } }
});

// 5. Stacked
new Chart(document.getElementById('stackedChart'), {
    type:'bar',
    data:{ labels:monthlyLabels, datasets:[
        { label:'Pending',  data:stackedPending,  backgroundColor:C.accent, borderRadius:{topLeft:0,topRight:0,bottomLeft:4,bottomRight:4}, borderSkipped:false },
        { label:'Resolved', data:stackedResolved, backgroundColor:C.green,  borderRadius:{topLeft:4,topRight:4,bottomLeft:0,bottomRight:0}, borderSkipped:false }
    ]},
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'top',labels:{usePointStyle:true,pointStyleWidth:10,padding:16}}, tooltip:{mode:'index',intersect:false} }, scales:{ x:{stacked:true,grid:{display:false}}, y:{stacked:true,beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}} } }
});

// ── Tabs ──────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (btn) btn.classList.add('active');
    else document.getElementById('btn-' + name)?.classList.add('active');
}
switchTab('<?= $activeTab ?>', null);

// ── Modal ─────────────────────────────────────────────────
function openEdit(id, type, desc, date, status) {
    document.getElementById('edit_violation_id').value   = id;
    document.getElementById('edit_violation_type').value = type;
    document.getElementById('edit_description').value    = desc;
    document.getElementById('edit_date').value           = date;
    document.getElementById('edit_status').value         = status;
    document.getElementById('editModal').classList.add('active');
}
function closeModal() { document.getElementById('editModal').classList.remove('active'); }
document.getElementById('editModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeModal(); });

// ── Search ────────────────────────────────────────────────
function searchTable(tId, iId, nId) {
    const q = document.getElementById(iId).value.toLowerCase();
    let vis = 0;
    document.querySelectorAll('#'+tId+' tbody tr').forEach(r => {
        const show = r.innerText.toLowerCase().includes(q);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById(nId).style.display = vis===0 ? 'block' : 'none';
}

function filterViolations() {
    const q      = document.getElementById('searchViolations').value.toLowerCase();
    const status = document.getElementById('filterStatus').value;
    let vis = 0;
    document.querySelectorAll('#violationsTable tbody tr').forEach(r => {
        const show = r.innerText.toLowerCase().includes(q) && (!status || r.dataset.status===status);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('noViolationResults').style.display = vis===0 ? 'block' : 'none';
}
</script>
</body>
</html>