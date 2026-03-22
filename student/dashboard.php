<?php
require_once '../includes/config.php';
requireLogin('student');

$student_id = $_SESSION['student_id'];

// Get student info
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get violations
$stmt = $conn->prepare("
    SELECT v.*, u.name AS recorded_by_name
    FROM violations v
    JOIN users u ON v.recorded_by = u.id
    WHERE v.student_id = ?
    ORDER BY v.date_recorded DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$violations = $stmt->get_result();

$total    = $violations->num_rows;
$violations->data_seek(0);

$pending  = 0;
$resolved = 0;
$rows     = [];
while ($row = $violations->fetch_assoc()) {
    $rows[] = $row;
    if ($row['status'] === 'pending')  $pending++;
    if ($row['status'] === 'resolved') $resolved++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Student Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>My Dashboard</h2>
        <p>Welcome back, <?= htmlspecialchars($_SESSION['name']) ?>! Here are your violation records.</p>
    </div>

    <!-- Student Info Card -->
    <div class="card">
        <div class="card-title">Student Information</div>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
            <div>
                <div style="font-size:0.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Student No.</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <div>
                <div style="font-size:0.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Full Name</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['name']) ?></div>
            </div>
            <div>
                <div style="font-size:0.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Course</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['course']) ?></div>
            </div>
            <div>
                <div style="font-size:0.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Year Level</div>
                <div style="font-weight:700; margin-top:4px;">Year <?= $student['year_level'] ?></div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $total ?></div>
            <div class="stat-label">Total Violations</div>
        </div>
        <div class="stat-card accent">
            <div class="stat-num"><?= $pending ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card green">
            <div class="stat-num"><?= $resolved ?></div>
            <div class="stat-label">Resolved</div>
        </div>
    </div>

    <!-- Violations Table -->
    <div class="card">
        <div class="card-title">My Violation Records</div>

        <!-- Search Bar -->
        <div style="margin-bottom:1rem;">
            <input type="text" id="searchInput" class="form-control" placeholder="🔍 Search by violation type or status..." oninput="searchTable()" style="max-width:400px;">
        </div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-icon">✅</div>
                <p>No violations on record. Keep it up!</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table id="violationsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Violation Type</th>
                        <th>Description</th>
                        <th>Date</th>
                        <th>Recorded By</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $v): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                        <td><?= htmlspecialchars($v['description'] ?? '—') ?></td>
                        <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                        <td><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                        <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p id="noResults" style="display:none; text-align:center; color:var(--muted); padding:1rem;">No violations match your search.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function searchTable() {
    const input  = document.getElementById('searchInput').value.toLowerCase();
    const rows   = document.querySelectorAll('#violationsTable tbody tr');
    let visible  = 0;

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (text.includes(input)) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
}
</script>
</body>
</html>