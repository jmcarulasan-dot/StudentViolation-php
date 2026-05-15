<?php
require_once 'includes/config.php';
requireLogin();

// Only guidance and guard can export
if (!in_array($_SESSION['role'], ['guidance', 'guard'])) {
    header("Location: " . BASE_URL . "login.php?error=unauthorized");
    exit();
}

$role = $_SESSION['role'];

// ── Filters ────────────────────────────────────────────────
$filterStatus  = $_GET['status']     ?? '';
$filterStudent = trim($_GET['student'] ?? '');
$filterFrom    = $_GET['from']        ?? '';
$filterTo      = $_GET['to']          ?? '';
$filterType    = $_GET['type']        ?? '';

// ── Build Query ────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filterStatus)  { $where[] = "v.status = ?";               $params[] = $filterStatus;  $types .= 's'; }
if ($filterStudent) { $where[] = "s.name LIKE ?";              $params[] = "%$filterStudent%"; $types .= 's'; }
if ($filterFrom)    { $where[] = "v.date_recorded >= ?";       $params[] = $filterFrom;    $types .= 's'; }
if ($filterTo)      { $where[] = "v.date_recorded <= ?";       $params[] = $filterTo;      $types .= 's'; }
if ($filterType)    { $where[] = "v.violation_type = ?";       $params[] = $filterType;    $types .= 's'; }

// Guard only sees violations they recorded
if ($role === 'guard') {
    $where[] = "v.recorded_by = ?";
    $params[] = $_SESSION['user_id'];
    $types .= 'i';
}

$sql = "
    SELECT v.*, s.name AS student_name, s.student_no, s.course, s.year_level,
           u.name AS recorded_by_name
    FROM violations v
    JOIN students s ON v.student_id = s.id
    JOIN users u ON v.recorded_by = u.id
" . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
ORDER BY v.date_recorded DESC, v.id DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$violations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total    = count($violations);
$pending  = count(array_filter($violations, fn($v) => $v['status'] === 'pending'));
$resolved = $total - $pending;

// ── Stats for guidance ─────────────────────────────────────
$totalStudents = 0;
if ($role === 'guidance') {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM students");
    $totalStudents = $res->fetch_assoc()['cnt'];
}

// ── Violation types for filter dropdown ───────────────────
$vtypes = $conn->query("SELECT DISTINCT violation_type FROM violations ORDER BY violation_type")->fetch_all(MYSQLI_ASSOC);

// ── Handle PDF generation ─────────────────────────────────
if (isset($_GET['export'])) {
    // Build HTML for PDF
    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #1e2a3a; }
    .header { background: #1a3a5c; color: white; padding: 18px 24px; margin-bottom: 16px; }
    .header h1 { font-size: 16px; font-weight: 800; margin-bottom: 2px; }
    .header p  { font-size: 10px; opacity: .7; }
    .header-right { float: right; text-align: right; font-size: 10px; opacity: .8; }
    .stats { display: flex; gap: 12px; margin-bottom: 16px; padding: 0 24px; }
    .stat-box { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; text-align: center; }
    .stat-num  { font-size: 22px; font-weight: 800; color: #1a3a5c; }
    .stat-lbl  { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }
    .stat-box.accent .stat-num { color: #e84545; }
    .stat-box.green  .stat-num { color: #2ecc71; }
    .filters { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin: 0 24px 16px; font-size: 10px; color: #64748b; }
    .filters strong { color: #1a3a5c; }
    table { width: 100%; border-collapse: collapse; margin: 0 24px; width: calc(100% - 48px); }
    thead th { background: #1a3a5c; color: white; padding: 8px 10px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; }
    tbody tr { border-bottom: 1px solid #e2e8f0; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody td { padding: 7px 10px; font-size: 10px; vertical-align: middle; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
    .badge-pending  { background: #fef3c7; color: #92400e; }
    .badge-resolved { background: #d1fae5; color: #065f46; }
    .appeal-none     { background: #f1f5f9; color: #64748b; }
    .appeal-pending  { background: #fef3c7; color: #92400e; }
    .appeal-approved { background: #d1fae5; color: #065f46; }
    .appeal-rejected { background: #fee2e2; color: #991b1b; }
    .footer { margin-top: 20px; padding: 10px 24px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; display: flex; justify-content: space-between; }
    .section-title { padding: 0 24px; margin-bottom: 10px; font-size: 12px; font-weight: 700; color: #1a3a5c; border-left: 3px solid #e84545; padding-left: 10px; margin-left: 24px; }
    @media print { body { -webkit-print-color-adjust: exact; } }
</style>
</head>
<body>

<div class="header">
    <div class="header-right">
        Generated: ' . date('F d, Y h:i A') . '<br>
        By: ' . htmlspecialchars($_SESSION['name']) . ' (' . ucfirst($role) . ')
    </div>
    <h1>ACLC College of Mandaue</h1>
    <p>Student Violation System — Violations Report</p>
</div>

<table style="margin:0 24px 16px; width:calc(100% - 48px); border-collapse:collapse;">
<tr>
<td style="width:33%; vertical-align:top;">
    <div style="border:1.5px solid #e2e8f0; border-radius:8px; padding:10px 14px; text-align:center;">
        <div style="font-size:22px; font-weight:800; color:#1a3a5c;">' . $total . '</div>
        <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Total Violations</div>
    </div>
</td>
<td style="width:33%; vertical-align:top; padding-left:8px;">
    <div style="border:1.5px solid #e2e8f0; border-radius:8px; padding:10px 14px; text-align:center;">
        <div style="font-size:22px; font-weight:800; color:#e84545;">' . $pending . '</div>
        <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Pending</div>
    </div>
</td>
<td style="width:33%; vertical-align:top; padding-left:8px;">
    <div style="border:1.5px solid #e2e8f0; border-radius:8px; padding:10px 14px; text-align:center;">
        <div style="font-size:22px; font-weight:800; color:#2ecc71;">' . $resolved . '</div>
        <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Resolved</div>
    </div>
</td>
</tr>
</table>';

    // Active filters info
    $activeFilters = [];
    if ($filterStatus)  $activeFilters[] = "Status: <strong>" . ucfirst($filterStatus) . "</strong>";
    if ($filterStudent) $activeFilters[] = "Student: <strong>" . htmlspecialchars($filterStudent) . "</strong>";
    if ($filterFrom)    $activeFilters[] = "From: <strong>" . $filterFrom . "</strong>";
    if ($filterTo)      $activeFilters[] = "To: <strong>" . $filterTo . "</strong>";
    if ($filterType)    $activeFilters[] = "Type: <strong>" . htmlspecialchars($filterType) . "</strong>";

    if ($activeFilters) {
        $html .= '<div class="filters">Filters applied: ' . implode(' &nbsp;·&nbsp; ', $activeFilters) . '</div>';
    }

    $html .= '<div class="section-title">Violation Records (' . $total . ')</div>';

    $html .= '<table>
<thead>
<tr>
    <th>#</th>
    <th>Student No.</th>
    <th>Student Name</th>
    <th>Course / Year</th>
    <th>Violation Type</th>
    <th>Description</th>
    <th>Date</th>
    <th>Recorded By</th>
    <th>Status</th>
    <th>Appeal</th>
</tr>
</thead>
<tbody>';

    if (empty($violations)) {
        $html .= '<tr><td colspan="10" style="text-align:center; padding:20px; color:#64748b;">No violations found.</td></tr>';
    } else {
        foreach ($violations as $i => $v) {
            $as = $v['appeal_status'] ?? 'none';
            $html .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td><code>' . htmlspecialchars($v['student_no']) . '</code></td>
                <td>' . htmlspecialchars($v['student_name']) . '</td>
                <td>' . htmlspecialchars($v['course']) . ' Y' . $v['year_level'] . '</td>
                <td><strong>' . htmlspecialchars($v['violation_type']) . '</strong></td>
                <td style="color:#64748b;">' . htmlspecialchars($v['description'] ?? '—') . '</td>
                <td>' . date('M d, Y', strtotime($v['date_recorded'])) . '</td>
                <td style="color:#64748b;">' . htmlspecialchars($v['recorded_by_name']) . '</td>
                <td><span class="badge badge-' . $v['status'] . '">' . ucfirst($v['status']) . '</span></td>
                <td><span class="badge appeal-' . $as . '">' . ($as === 'none' ? '—' : ucfirst($as)) . '</span></td>
            </tr>';
        }
    }

    $html .= '</tbody></table>';
    $html .= '<div class="footer">
        <span>ACLC SVS — Student Violation System &nbsp;·&nbsp; Confidential</span>
        <span>Printed: ' . date('F d, Y h:i A') . '</span>
    </div>';
    $html .= '</body></html>';

    // Output as printable HTML page
    echo $html;
    echo '<script>window.onload = function() { window.print(); }</script>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Export Report</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        .preview-table-wrap { overflow-x:auto; border-radius:8px; max-height:500px; overflow-y:auto; }
        .export-card { background:linear-gradient(135deg,#0a1628,#1a3a5c); border-radius:var(--radius); padding:1.6rem; margin-bottom:1.4rem; color:white; }
        .export-card h3 { font-size:1rem; font-weight:800; margin-bottom:.3rem; }
        .export-card p  { font-size:.84rem; opacity:.7; }
        .filter-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin-bottom:1.2rem; }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>📄 Export Violations Report</h2>
        <p>Filter and export violation records as a printable PDF report.</p>
    </div>

    <!-- Export Card -->
    <div class="export-card">
        <h3>📊 Generate PDF Report</h3>
        <p>Apply filters below, preview the results, then click Export to PDF to download your report.</p>
    </div>

    <!-- Filter Form -->
    <div class="card">
        <div class="card-title">🔍 Filter Options</div>
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="form-group" style="margin:0;">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
                        <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Violation Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <?php foreach ($vtypes as $vt): ?>
                        <option value="<?= htmlspecialchars($vt['violation_type']) ?>"
                                <?= $filterType === $vt['violation_type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($vt['violation_type']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Student Name</label>
                    <input type="text" name="student" class="form-control"
                           placeholder="Search by name..."
                           value="<?= htmlspecialchars($filterStudent) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Date From</label>
                    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($filterFrom) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Date To</label>
                    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($filterTo) ?>">
                </div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">🔍 Preview</button>
                <button type="button" class="btn btn-outline" onclick="clearFilters()">✕ Clear</button>
                <a href="<?= '?' . http_build_query(array_merge($_GET, ['export' => '1'])) ?>"
                   target="_blank" class="btn btn-accent">
                    📄 Export to PDF
                </a>
                <?php if ($role === 'guidance'): ?>
                <a href="<?= BASE_URL ?>guidance/dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>guard/dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Summary Stats -->
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f0fe;">📋</div>
            <div class="stat-info"><div class="stat-num"><?= $total ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon" style="background:#fee2e2;">⚠️</div>
            <div class="stat-info"><div class="stat-num"><?= $pending ?></div><div class="stat-label">Pending</div></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon" style="background:#d1fae5;">✅</div>
            <div class="stat-info"><div class="stat-num"><?= $resolved ?></div><div class="stat-label">Resolved</div></div>
        </div>
    </div>

    <!-- Preview Table -->
    <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
            <div class="card-title" style="margin:0; border:none; padding:0;">
                Preview — <?= $total ?> record<?= $total != 1 ? 's' : '' ?>
            </div>
            <a href="<?= '?' . http_build_query(array_merge($_GET, ['export' => '1'])) ?>"
               target="_blank" class="btn btn-accent btn-sm">
                📄 Export to PDF
            </a>
        </div>
        <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

        <?php if (empty($violations)): ?>
            <div class="empty-state"><div class="empty-icon">📭</div><p>No violations match your filters.</p></div>
        <?php else: ?>
        <div class="preview-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student No.</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Violation</th>
                        <th>Date</th>
                        <th>Recorded By</th>
                        <th>Status</th>
                        <th>Appeal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($violations as $i => $v):
                        $as = $v['appeal_status'] ?? 'none';
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><code style="font-size:.78rem; color:var(--primary);"><?= htmlspecialchars($v['student_no']) ?></code></td>
                        <td><?= htmlspecialchars($v['student_name']) ?></td>
                        <td><?= htmlspecialchars($v['course']) ?> Y<?= $v['year_level'] ?></td>
                        <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong>
                            <?php if ($v['description']): ?>
                            <div style="font-size:.74rem; color:var(--muted);"><?= htmlspecialchars($v['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                        <td style="font-size:.82rem; color:var(--muted);"><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                        <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                        <td>
                            <span class="badge" style="
                                <?= $as === 'pending'  ? 'background:#fef3c7;color:#92400e;' : '' ?>
                                <?= $as === 'approved' ? 'background:#d1fae5;color:#065f46;' : '' ?>
                                <?= $as === 'rejected' ? 'background:#fee2e2;color:#991b1b;' : '' ?>
                                <?= $as === 'none'     ? 'background:#f1f5f9;color:#64748b;' : '' ?>
                            "><?= $as === 'none' ? '—' : ucfirst($as) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
function clearFilters() {
    window.location.href = '<?= BASE_URL ?>export_pdf.php';
}
</script>
</body>
</html>