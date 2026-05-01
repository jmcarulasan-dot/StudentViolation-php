<?php
require_once '../includes/config.php';
requireLogin('guidance');

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add student ───────────────────────────────────────
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
            $stmt2->execute()
                ? $success = "Student added successfully!"
                : $error   = "Student added but failed to create login.";
        } else {
            $error = "Failed to add student. Student No. may already exist.";
        }
    }

    // ── Update violation status ───────────────────────────
    if ($action === 'update_status') {
        $vid    = intval($_POST['violation_id']);
        $status = $_POST['status'];
        $stmt   = $conn->prepare("UPDATE violations SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $vid);
        $stmt->execute();
        $success = "Violation status updated!";
    }

    // ── Edit violation ────────────────────────────────────
    if ($action === 'edit_violation') {
        $vid            = intval($_POST['violation_id']);
        $violation_type = trim($_POST['violation_type']);
        $description    = trim($_POST['description']);
        $date_recorded  = $_POST['date_recorded'];
        $status         = $_POST['status'];

        $stmt = $conn->prepare("UPDATE violations SET violation_type=?, description=?, date_recorded=?, status=? WHERE id=?");
        $stmt->bind_param("ssssi", $violation_type, $description, $date_recorded, $status, $vid);
        $stmt->execute() ? $success = "Violation updated!" : $error = "Failed to update violation.";
    }

    // ── Delete violation ──────────────────────────────────
    if ($action === 'delete_violation') {
        $vid  = intval($_POST['violation_id']);
        $stmt = $conn->prepare("DELETE FROM violations WHERE id = ?");
        $stmt->bind_param("i", $vid);
        $stmt->execute();
        $success = "Violation deleted.";
    }

    // ── Delete student ────────────────────────────────────
    if ($action === 'delete_student') {
        $sid  = intval($_POST['student_id']);
        foreach (["DELETE FROM violations WHERE student_id=?","DELETE FROM users WHERE student_id=?","DELETE FROM students WHERE id=?"] as $sql) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $sid);
            $stmt->execute();
        }
        $success = "Student and all related records deleted.";
    }

    // ── Review appeal (approve / reject) ─────────────────
    if ($action === 'review_appeal') {
        $vid           = intval($_POST['violation_id']);
        $appealDecision = $_POST['appeal_decision']; // 'approved' or 'rejected'
        $appealRemarks  = trim($_POST['appeal_remarks'] ?? '');

        if (!in_array($appealDecision, ['approved', 'rejected'])) {
            $error = "Invalid appeal decision.";
        } else {
            // Only update appeal_status and appeal_remarks — violation status unchanged
            // Guidance will separately decide what to do with the violation itself
            $stmt = $conn->prepare("UPDATE violations SET appeal_status=?, appeal_remarks=? WHERE id=?");
            $stmt->bind_param("ssi", $appealDecision, $appealRemarks, $vid);
            $stmt->execute()
                ? $success = "Appeal " . ucfirst($appealDecision) . "d. You can now separately resolve or keep the violation as-is."
                : $error   = "Failed to process appeal.";
        }
    }
}

// ── Data ───────────────────────────────────────────────────
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

// Appeals summary
$pendingAppealsCount  = count(array_filter($violations, fn($v) => ($v['appeal_status'] ?? 'none') === 'pending'));
$approvedAppealsCount = count(array_filter($violations, fn($v) => ($v['appeal_status'] ?? 'none') === 'approved'));

// Monthly trend
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $monthlyData[$label] = 0;
}
foreach ($violations as $v) {
    $label = date('M Y', strtotime($v['date_recorded']));
    if (isset($monthlyData[$label])) $monthlyData[$label]++;
}

// By type
$typeData = [];
foreach ($violations as $v) {
    $t = $v['violation_type'];
    $typeData[$t] = ($typeData[$t] ?? 0) + 1;
}
arsort($typeData);

// By course
$cr = $conn->query("SELECT s.course, COUNT(v.id) AS cnt FROM violations v JOIN students s ON v.student_id=s.id GROUP BY s.course ORDER BY cnt DESC");
$courseData = [];
while ($row = $cr->fetch_assoc()) $courseData[$row['course']] = (int)$row['cnt'];

// Stacked monthly
$statusMonthly = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $statusMonthly[$label] = ['pending'=>0,'resolved'=>0];
}
foreach ($violations as $v) {
    $label = date('M Y', strtotime($v['date_recorded']));
    if (isset($statusMonthly[$label])) $statusMonthly[$label][$v['status']]++;
}

// Top 5 students
$topStudents = $conn->query("
    SELECT s.name, s.student_no, COUNT(v.id) AS cnt
    FROM violations v JOIN students s ON v.student_id=s.id
    GROUP BY s.id ORDER BY cnt DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Violation count per student
$vcounts = [];
foreach ($violations as $v) $vcounts[$v['student_no']] = ($vcounts[$v['student_no']] ?? 0) + 1;

// Active tab after POST
$activeTab = 'overview';
if ($_POST) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_student')    $activeTab = 'add-student';
    elseif ($act === 'delete_student') $activeTab = 'students';
    elseif ($act === 'review_appeal') $activeTab = 'appeals';
    elseif (in_array($act, ['update_status','edit_violation','delete_violation'])) $activeTab = 'violations';
}
if (isset($_GET['tab'])) $activeTab = $_GET['tab'];
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
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.48); z-index:999; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
        .modal-overlay.active { display:flex; }
        .action-form { display:inline; }

        /* Top students */
        .top-item { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); }
        .top-item:last-child { border-bottom:none; }
        .top-rank { width:26px; height:26px; border-radius:50%; background:var(--primary); color:white; font-size:.7rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .top-rank.gold   { background:#f0a500; }
        .top-rank.silver { background:#94a3b8; }
        .top-rank.bronze { background:#b45309; }
        .top-info { flex:1; min-width:0; }
        .top-info strong { display:block; font-size:.84rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .top-info span   { font-size:.7rem; color:var(--muted); }
        .bar-mini { height:5px; border-radius:3px; background:var(--accent); margin-top:4px; }
        .top-cnt  { font-size:.95rem; font-weight:800; color:var(--accent); min-width:24px; text-align:right; }

        /* Appeal styles */
        .appeal-badge {
            display: inline-block; padding: 2px 9px; border-radius: 20px;
            font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
        }
        .appeal-none     { background:#f1f5f9; color:#64748b; }
        .appeal-pending  { background:#fef3c7; color:#92400e; }
        .appeal-approved { background:#d1fae5; color:#065f46; }
        .appeal-rejected { background:#fee2e2; color:#991b1b; }

        /* Appeal card in Appeals tab */
        .appeal-card {
            border: 1.5px solid var(--border); border-radius: var(--radius);
            padding: 1.1rem 1.2rem; margin-bottom: 1rem;
            transition: box-shadow .15s;
        }
        .appeal-card:hover { box-shadow: 0 4px 18px rgba(26,58,92,.09); }
        .appeal-card.urgent { border-color: #fcd34d; background: #fffbeb; }

        .appeal-card-header {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            margin-bottom: .8rem;
        }
        .appeal-student-name { font-size: .95rem; font-weight: 800; color: var(--primary); }
        .appeal-student-meta { font-size: .78rem; color: var(--muted); margin-top: 2px; }
        .appeal-text-box {
            background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;
            padding: .7rem .9rem; font-size: .84rem; color: var(--text);
            line-height: 1.55; margin-bottom: .9rem;
        }
        .appeal-text-label { font-size: .72rem; font-weight: 700; color: var(--muted);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }

        .review-form { display:none; margin-top:.8rem; }
        .review-form.open { display:block; }

        /* Remarks display */
        .remarks-box {
            background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
            padding: .6rem .9rem; font-size: .82rem; color: #166534; margin-top: .6rem;
        }
        .remarks-box.rejected-remarks {
            background: #fef2f2; border-color: #fecaca; color: #991b1b;
        }
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
            <input type="hidden" name="violation_id" id="edit_vid">
            <div class="form-group">
                <label>Violation Type <span style="color:var(--accent)">*</span></label>
                <select name="violation_type" id="edit_type" class="form-control" required>
                    <option>Late</option><option>Cutting Class</option>
                    <option>Improper Uniform</option><option>Disruptive Behavior</option>
                    <option>Vandalism</option><option>Prohibited Items</option><option>Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" id="edit_desc" class="form-control">
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

<!-- Appeal Review Modal -->
<div class="modal-overlay" id="appealModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-title">📋 Review Student Appeal</div>
        <div id="appealModalBody">
            <!-- filled by JS -->
        </div>
        <form method="POST" id="appealReviewForm">
            <input type="hidden" name="action" value="review_appeal">
            <input type="hidden" name="violation_id" id="appeal_vid">
            <input type="hidden" name="appeal_decision" id="appeal_decision_input">
            <div class="form-group" style="margin-top:.9rem;">
                <label>Remarks / Notes <span style="color:var(--accent)">*</span></label>
                <textarea name="appeal_remarks" id="appeal_remarks_input" class="form-control"
                          rows="3" required
                          placeholder="Add your reason for approving or rejecting this appeal..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeAppealModal()">Cancel</button>
                <button type="submit" id="appealRejectBtn" class="btn btn-danger"
                        onclick="document.getElementById('appeal_decision_input').value='rejected'">
                    ❌ Reject Appeal
                </button>
                <button type="submit" id="appealApproveBtn" class="btn btn-success"
                        onclick="document.getElementById('appeal_decision_input').value='approved'">
                    ✅ Approve Appeal
                </button>
            </div>
        </form>
    </div>
</div>

<div class="page-wrapper">
    <div class="page-header">
        <h2>Guidance Admin Dashboard</h2>
        <p>Full control over students, violations, appeals, and analytics.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- ══ TABS ══ -->
    <div class="tabs">
        <button class="tab-btn" id="btn-overview"    onclick="switchTab('overview',this)">📊 Overview</button>
        <button class="tab-btn" id="btn-analytics"   onclick="switchTab('analytics',this)">📈 Analytics</button>
        <button class="tab-btn" id="btn-violations"  onclick="switchTab('violations',this)">
            📂 Violations
            <span style="background:var(--accent);color:#fff;border-radius:10px;padding:1px 8px;font-size:.68rem;margin-left:5px;"><?= $totalViolations ?></span>
        </button>
        <button class="tab-btn" id="btn-appeals" onclick="switchTab('appeals',this)">
            📋 Appeals
            <?php if ($pendingAppealsCount > 0): ?>
            <span style="background:#f0a500;color:#fff;border-radius:10px;padding:1px 8px;font-size:.68rem;margin-left:5px;"><?= $pendingAppealsCount ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn" id="btn-students"    onclick="switchTab('students',this)">
            👥 Students
            <span style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 8px;font-size:.68rem;margin-left:5px;"><?= $totalStudents ?></span>
        </button>
        <button class="tab-btn" id="btn-add-student" onclick="switchTab('add-student',this)">➕ Add Student</button>
    </div>

    <!-- ══ OVERVIEW ══ -->
    <div id="tab-overview" class="tab-content">
        <!-- Mini stat cards -->
        <div class="mini-row" style="grid-template-columns:repeat(6,1fr);">
            <div class="mini-card">
                <div class="mini-icon" style="background:#e8f0fe;">👥</div>
                <div><div class="mini-num"><?= $totalStudents ?></div><div class="mini-lbl">Students</div></div>
            </div>
            <div class="mini-card gld">
                <div class="mini-icon" style="background:#fef3c7;">📋</div>
                <div><div class="mini-num"><?= $totalViolations ?></div><div class="mini-lbl">Violations</div></div>
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
            <!-- Appeals mini card -->
            <div class="mini-card" style="<?= $pendingAppealsCount > 0 ? 'border-color:#fcd34d; background:#fffbeb;' : '' ?>">
                <div class="mini-icon" style="background:#fef3c7;">📝</div>
                <div>
                    <div class="mini-num" style="color:#f0a500;"><?= $pendingAppealsCount ?></div>
                    <div class="mini-lbl">Appeals</div>
                </div>
            </div>
        </div>

        <!-- Pending appeals quick alert -->
        <?php if ($pendingAppealsCount > 0): ?>
        <div class="alert alert-info" style="margin-bottom:1rem; cursor:pointer;"
             onclick="switchTab('appeals', document.getElementById('btn-appeals'))">
            📋 <strong><?= $pendingAppealsCount ?> pending appeal<?= $pendingAppealsCount > 1 ? 's' : '' ?></strong>
            awaiting your review.
            <span style="font-weight:700; text-decoration:underline; margin-left:8px;">Review now →</span>
        </div>
        <?php endif; ?>

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
        <div class="g2e">
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--accent);"></span>Top 5 Students — Most Violations</div>
                <?php if (empty($topStudents)): ?>
                    <p style="color:var(--muted); font-size:.85rem; text-align:center; padding:2rem 0;">No data yet.</p>
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
                    <p style="color:var(--muted); font-size:.85rem; text-align:center; padding:2rem 0;">None yet.</p>
                <?php else: ?>
                <?php foreach (array_slice($violations, 0, 5) as $rv): ?>
                <div style="padding:8px 0; border-bottom:1px solid var(--border);">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <div style="font-size:.84rem; font-weight:700;"><?= htmlspecialchars($rv['student_name']) ?></div>
                            <div style="font-size:.73rem; color:var(--muted);"><?= htmlspecialchars($rv['violation_type']) ?> · <?= date('M d, Y', strtotime($rv['date_recorded'])) ?></div>
                        </div>
                        <span class="badge badge-<?= $rv['status'] ?>"><?= ucfirst($rv['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:.9rem;">
                    <button class="btn btn-outline btn-sm" onclick="switchTab('violations', document.getElementById('btn-violations'))">View All →</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ ANALYTICS ══ -->
    <div id="tab-analytics" class="tab-content">
        <div class="g2e" style="margin-bottom:1rem;">
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:var(--gold);"></span>Violations by Type</div>
                <div class="cwrap tall"><canvas id="barChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-title"><span class="cdot" style="background:#8b5cf6;"></span>Violations by Course</div>
                <div class="cwrap tall"><canvas id="courseChart"></canvas></div>
            </div>
        </div>

        <div class="chart-card" style="margin-bottom:1rem;">
            <div class="chart-title"><span class="cdot" style="background:var(--success);"></span>Pending vs Resolved — Monthly Breakdown</div>
            <div class="cwrap tall"><canvas id="stackedChart"></canvas></div>
        </div>

        <div class="card">
            <div class="card-title">📋 Violation Type Summary</div>
            <?php if (empty($typeData)): ?>
                <div class="empty-state"><div class="empty-icon">📭</div><p>No data yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Violation Type</th><th>Count</th><th>% Share</th><th style="width:200px;">Bar</th></tr></thead>
                    <tbody>
                        <?php foreach ($typeData as $type => $cnt):
                            $pct = $totalViolations > 0 ? round(($cnt/$totalViolations)*100, 1) : 0;
                        ?>
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

    <!-- ══ VIOLATIONS ══ -->
    <div id="tab-violations" class="tab-content">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                <div class="card-title" style="margin:0; border:none; padding:0;">
                    All Violations <span style="font-size:.8rem; font-weight:600; color:var(--muted); margin-left:6px;">(<?= $totalViolations ?> total)</span>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <select id="filterStatus" onchange="filterViolations()" class="form-control" style="max-width:140px; margin:0; padding:8px 12px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="resolved">Resolved</option>
                    </select>
                    <select id="filterAppeal" onchange="filterViolations()" class="form-control" style="max-width:150px; margin:0; padding:8px 12px;">
                        <option value="">All Appeals</option>
                        <option value="pending">Appeal Pending</option>
                        <option value="approved">Appeal Approved</option>
                        <option value="rejected">Appeal Rejected</option>
                        <option value="none">No Appeal</option>
                    </select>
                    <input type="text" id="searchViolations" class="form-control"
                           placeholder="🔍 Search..." oninput="filterViolations()"
                           style="max-width:200px; margin:0;">
                </div>
            </div>
            <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

            <?php if (empty($violations)): ?>
                <div class="empty-state"><div class="empty-icon">📂</div><p>No violations yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table id="violationsTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Student No.</th><th>Student</th>
                            <th>Violation</th><th>Date</th><th>By</th>
                            <th>Status</th><th>Appeal</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $i => $v):
                            $appealStatus = $v['appeal_status'] ?? 'none';
                        ?>
                        <tr data-status="<?= $v['status'] ?>" data-appeal="<?= $appealStatus ?>">
                            <td><?= $i+1 ?></td>
                            <td><code style="font-size:.8rem; color:var(--primary);"><?= htmlspecialchars($v['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($v['student_name']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($v['violation_type']) ?></strong>
                                <?php if ($v['description']): ?>
                                <div style="font-size:.75rem; color:var(--muted);"><?= htmlspecialchars($v['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                            <td style="font-size:.82rem; color:var(--muted);"><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                            <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                            <td>
                                <?php if ($appealStatus === 'pending'): ?>
                                    <button class="btn btn-sm btn-outline" style="border-color:#f0a500; color:#92400e; font-size:.73rem;"
                                            onclick="openAppealModal(
                                                <?= $v['id'] ?>,
                                                '<?= addslashes($v['student_name']) ?>',
                                                '<?= addslashes($v['student_no']) ?>',
                                                '<?= addslashes($v['violation_type']) ?>',
                                                '<?= addslashes($v['appeal_text'] ?? '') ?>'
                                            )">
                                        📝 Review
                                    </button>
                                <?php else: ?>
                                    <span class="appeal-badge appeal-<?= $appealStatus ?>">
                                        <?= $appealStatus === 'none' ? '—' : ucfirst($appealStatus) ?>
                                    </span>
                                    <?php if (!empty($v['appeal_remarks'])): ?>
                                    <div style="font-size:.72rem; color:var(--muted); margin-top:3px; max-width:160px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                         title="<?= htmlspecialchars($v['appeal_remarks']) ?>">
                                        <?= htmlspecialchars($v['appeal_remarks']) ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="display:flex; gap:4px; flex-wrap:wrap;">
                                <button class="btn btn-sm btn-outline"
                                        onclick="openEdit(<?= $v['id'] ?>,'<?= addslashes($v['violation_type']) ?>','<?= addslashes($v['description'] ?? '') ?>','<?= $v['date_recorded'] ?>','<?= $v['status'] ?>')">
                                    Edit
                                </button>
                                <form class="action-form" method="POST">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $v['status']==='pending'?'resolved':'pending' ?>">
                                    <button type="submit" class="btn btn-sm <?= $v['status']==='pending'?'btn-success':'btn-outline' ?>">
                                        <?= $v['status']==='pending'?'Resolve':'Reopen' ?>
                                    </button>
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
            <p id="noVResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ APPEALS ══ -->
    <div id="tab-appeals" class="tab-content">
        <div class="page-header" style="margin-bottom:1rem;">
            <h2 style="font-size:1.2rem;">Student Appeals</h2>
            <p style="font-size:.85rem;">Review and respond to student appeals for their violations.</p>
        </div>

        <?php
        $pendingAppeals  = array_filter($violations, fn($v) => ($v['appeal_status'] ?? 'none') === 'pending');
        $reviewedAppeals = array_filter($violations, fn($v) => in_array($v['appeal_status'] ?? 'none', ['approved','rejected']));
        ?>

        <!-- Pending Appeals -->
        <div class="card" style="margin-bottom:1.2rem;">
            <div class="card-title">
                ⏳ Pending Appeals
                <?php if (count($pendingAppeals) > 0): ?>
                <span style="background:#f0a500; color:#fff; border-radius:10px; padding:2px 9px; font-size:.7rem; margin-left:6px; font-weight:800;"><?= count($pendingAppeals) ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($pendingAppeals)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <p>No pending appeals — all caught up!</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingAppeals as $v): ?>
                <div class="appeal-card urgent">
                    <div class="appeal-card-header">
                        <div>
                            <div class="appeal-student-name">
                                <?= htmlspecialchars($v['student_name']) ?>
                                <span style="font-weight:400; font-size:.82rem; color:var(--muted);">
                                    · <?= htmlspecialchars($v['student_no']) ?>
                                </span>
                            </div>
                            <div class="appeal-student-meta">
                                Violation: <strong><?= htmlspecialchars($v['violation_type']) ?></strong>
                                <?php if ($v['description']): ?> — <?= htmlspecialchars($v['description']) ?><?php endif; ?>
                                · <?= date('M d, Y', strtotime($v['date_recorded'])) ?>
                                · Recorded by <?= htmlspecialchars($v['recorded_by_name']) ?>
                            </div>
                            <div style="margin-top:5px;">
                                <span class="badge badge-pending">Pending</span>
                                <span class="appeal-badge appeal-pending" style="margin-left:5px;">Appeal Pending</span>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline no-print"
                                style="border-color:#f0a500; color:#92400e; white-space:nowrap;"
                                onclick="openAppealModal(
                                    <?= $v['id'] ?>,
                                    '<?= addslashes($v['student_name']) ?>',
                                    '<?= addslashes($v['student_no']) ?>',
                                    '<?= addslashes($v['violation_type']) ?>',
                                    '<?= addslashes($v['appeal_text'] ?? '') ?>'
                                )">
                            📋 Review Appeal
                        </button>
                    </div>

                    <div class="appeal-text-label">Student's Appeal Reason:</div>
                    <div class="appeal-text-box">
                        "<?= nl2br(htmlspecialchars($v['appeal_text'] ?? '—')) ?>"
                    </div>

                    <!-- Inline quick-review form -->
                    <form method="POST">
                        <input type="hidden" name="action" value="review_appeal">
                        <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                        <input type="hidden" name="appeal_decision" id="inline_decision_<?= $v['id'] ?>" value="">
                        <div class="form-group" style="margin-bottom:.7rem;">
                            <label style="font-size:.8rem;">Remarks / Notes <span style="color:var(--accent)">*</span></label>
                            <textarea name="appeal_remarks" class="form-control" rows="2" required
                                      placeholder="Explain your decision to the student..."></textarea>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="submit" class="btn btn-success btn-sm"
                                    onclick="document.getElementById('inline_decision_<?= $v['id'] ?>').value='approved'">
                                ✅ Approve Appeal
                            </button>
                            <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="document.getElementById('inline_decision_<?= $v['id'] ?>').value='rejected'">
                                ❌ Reject Appeal
                            </button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Reviewed Appeals -->
        <?php if (!empty($reviewedAppeals)): ?>
        <div class="card">
            <div class="card-title">📁 Reviewed Appeals</div>
            <?php foreach ($reviewedAppeals as $v):
                $as = $v['appeal_status'];
            ?>
            <div class="appeal-card">
                <div class="appeal-card-header">
                    <div>
                        <div class="appeal-student-name">
                            <?= htmlspecialchars($v['student_name']) ?>
                            <span style="font-weight:400; font-size:.82rem; color:var(--muted);">
                                · <?= htmlspecialchars($v['student_no']) ?>
                            </span>
                        </div>
                        <div class="appeal-student-meta">
                            Violation: <strong><?= htmlspecialchars($v['violation_type']) ?></strong>
                            · <?= date('M d, Y', strtotime($v['date_recorded'])) ?>
                        </div>
                        <div style="margin-top:5px; display:flex; gap:5px; flex-wrap:wrap;">
                            <span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span>
                            <span class="appeal-badge appeal-<?= $as ?>"><?= ucfirst($as) ?></span>
                        </div>
                    </div>
                </div>
                <?php if (!empty($v['appeal_text'])): ?>
                <div class="appeal-text-label">Student's Reason:</div>
                <div class="appeal-text-box" style="margin-bottom:.6rem;">
                    "<?= nl2br(htmlspecialchars($v['appeal_text'])) ?>"
                </div>
                <?php endif; ?>
                <?php if (!empty($v['appeal_remarks'])): ?>
                <div class="<?= $as === 'approved' ? 'remarks-box' : 'remarks-box rejected-remarks' ?>">
                    <strong>Your remarks:</strong> <?= nl2br(htmlspecialchars($v['appeal_remarks'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ STUDENTS ══ -->
    <div id="tab-students" class="tab-content">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
                <div class="card-title" style="margin:0; border:none; padding:0;">
                    All Students <span style="font-size:.8rem; font-weight:600; color:var(--muted); margin-left:6px;">(<?= $totalStudents ?> enrolled)</span>
                </div>
                <input type="text" id="searchStudents" class="form-control"
                       placeholder="🔍 Search students..." oninput="searchTable('studentsTable','searchStudents','noSResults')"
                       style="max-width:240px; margin:0;">
            </div>
            <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

            <?php if (empty($students)): ?>
                <div class="empty-state"><div class="empty-icon">👥</div><p>No students yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table id="studentsTable">
                    <thead>
                        <tr><th>#</th><th>Student No.</th><th>Name</th><th>Course</th><th>Year</th><th>Violations</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $s):
                            $vc = $vcounts[$s['student_no']] ?? 0;
                        ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><code style="font-size:.8rem; color:var(--primary);"><?= htmlspecialchars($s['student_no']) ?></code></td>
                            <td>
                                <?php if (!empty($s['profile_photo'])): ?>
                                <img src="<?= BASE_URL ?>uploads/profile/<?= htmlspecialchars($s['profile_photo']) ?>"
                                     style="width:26px; height:26px; border-radius:50%; object-fit:cover; margin-right:6px; vertical-align:middle; border:1.5px solid var(--border);">
                                <?php endif; ?>
                                <?= htmlspecialchars($s['name']) ?>
                            </td>
                            <td><?= htmlspecialchars($s['course']) ?></td>
                            <td>Yr <?= $s['year_level'] ?></td>
                            <td>
                                <?php if ($vc > 0): ?>
                                    <span class="badge" style="background:#fee2e2; color:#991b1b;"><?= $vc ?> violation<?= $vc>1?'s':'' ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:#d1fae5; color:#065f46;">Clean</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="action-form" method="POST" onsubmit="return confirm('Delete student and ALL violations?')">
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
            <p id="noSResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">No results found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ ADD STUDENT ══ -->
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

</div><!-- end page-wrapper -->

<script>
// ── Chart data ──────────────────────────────────────────────
const monthlyLabels   = <?= json_encode(array_keys($monthlyData)) ?>;
const monthlyCounts   = <?= json_encode(array_values($monthlyData)) ?>;
const typeLabels      = <?= json_encode(array_keys($typeData)) ?>;
const typeCounts      = <?= json_encode(array_values($typeData)) ?>;
const courseLabels    = <?= json_encode(array_keys($courseData)) ?>;
const courseCounts    = <?= json_encode(array_values($courseData)) ?>;
const stackedPending  = <?= json_encode(array_column(array_values($statusMonthly),'pending')) ?>;
const stackedResolved = <?= json_encode(array_column(array_values($statusMonthly),'resolved')) ?>;

Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

const C = { primary:'#1a3a5c', accent:'#e84545', gold:'#f0a500', green:'#2ecc71', purple:'#8b5cf6', teal:'#14b8a6', orange:'#f97316', blue:'#3b82f6' };
const PAL = Object.values(C);

new Chart(document.getElementById('lineChart'), {
    type:'line',
    data:{ labels:monthlyLabels, datasets:[{ label:'Violations', data:monthlyCounts, borderColor:C.primary, backgroundColor:'rgba(26,58,92,0.08)', borderWidth:2.5, pointBackgroundColor:C.primary, pointRadius:5, pointHoverRadius:7, fill:true, tension:0.4 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{mode:'index',intersect:false} }, scales:{ y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, x:{grid:{display:false}} } }
});

new Chart(document.getElementById('doughnutChart'), {
    type:'doughnut',
    data:{ labels:['Pending','Resolved'], datasets:[{ data:[<?= $pendingCount ?>,<?= $resolvedCount ?>], backgroundColor:[C.accent,C.green], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'65%', plugins:{ legend:{ position:'bottom', labels:{padding:16,usePointStyle:true,pointStyleWidth:10} } } }
});

new Chart(document.getElementById('barChart'), {
    type:'bar',
    data:{ labels:typeLabels, datasets:[{ data:typeCounts, backgroundColor:PAL.slice(0,typeLabels.length), borderRadius:5, borderSkipped:false }] },
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{ x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, y:{grid:{display:false}} } }
});

new Chart(document.getElementById('courseChart'), {
    type:'pie',
    data:{ labels:courseLabels, datasets:[{ data:courseCounts, backgroundColor:[C.primary,C.purple,C.gold,C.teal,C.orange], borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{padding:12,usePointStyle:true,pointStyleWidth:10,font:{size:11}} } } }
});

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

// ── Edit Violation Modal ──────────────────────────────────
function openEdit(id, type, desc, date, status) {
    document.getElementById('edit_vid').value    = id;
    document.getElementById('edit_type').value   = type;
    document.getElementById('edit_desc').value   = desc;
    document.getElementById('edit_date').value   = date;
    document.getElementById('edit_status').value = status;
    document.getElementById('editModal').classList.add('active');
}
function closeModal() { document.getElementById('editModal').classList.remove('active'); }
document.getElementById('editModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeModal(); });

// ── Appeal Review Modal ───────────────────────────────────
function openAppealModal(id, studentName, studentNo, violationType, appealText) {
    document.getElementById('appeal_vid').value = id;
    document.getElementById('appeal_remarks_input').value = '';

    document.getElementById('appealModalBody').innerHTML = `
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:9px; padding:.9rem 1rem; margin-bottom:.5rem;">
            <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; font-weight:700;">Student</div>
            <div style="font-weight:800; font-size:.95rem; color:var(--primary);">${studentName}</div>
            <div style="font-size:.78rem; color:var(--muted);">${studentNo} · ${violationType}</div>
        </div>
        <div style="margin-bottom:.3rem;">
            <div style="font-size:.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;">Student's Appeal Reason:</div>
            <div style="background:#fffbeb; border:1.5px solid #fcd34d; border-radius:8px; padding:.75rem 1rem; font-size:.86rem; font-style:italic; color:#78350f; line-height:1.55;">
                "${appealText || '—'}"
            </div>
        </div>
    `;
    document.getElementById('appealModal').classList.add('active');
}
function closeAppealModal() { document.getElementById('appealModal').classList.remove('active'); }
document.getElementById('appealModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeAppealModal(); });

// ── Search / Filter ───────────────────────────────────────
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
    const appeal = document.getElementById('filterAppeal').value;
    let vis = 0;
    document.querySelectorAll('#violationsTable tbody tr').forEach(r => {
        const matchText   = r.innerText.toLowerCase().includes(q);
        const matchStatus = !status || r.dataset.status === status;
        const matchAppeal = !appeal || r.dataset.appeal === appeal;
        const show = matchText && matchStatus && matchAppeal;
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('noVResults').style.display = vis===0 ? 'block' : 'none';
}
</script>
</body>
</html>