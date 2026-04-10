<?php
require_once '../includes/config.php';
requireLogin('student');

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

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

$total = $violations->num_rows;
$violations->data_seek(0);
$pending = 0; $resolved = 0; $rows = [];
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
    <style>
        .student-card {
            background: linear-gradient(135deg, #0d1b3e 0%, #1a3a5c 100%);
            border-radius: 14px;
            padding: 1.8rem;
            margin-bottom: 1.5rem;
            border: none;
        }

        .student-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.2rem;
            flex-wrap: wrap;
        }

        .student-avatar {
            width: 48px; height: 48px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .student-card-name {
            color: white;
            font-family: 'Syne', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
        }

        .student-card-no {
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
            margin-top: 2px;
        }

        .student-card-badge {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.8rem;
        }

        .student-info-item {
            background: rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 0.8rem 1rem;
        }

        .student-info-label {
            color: rgba(255,255,255,0.5);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .student-info-value {
            color: white;
            font-weight: 700;
            margin-top: 4px;
            font-size: 0.9rem;
        }

        /* Fix stat icon */
        .stat-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            min-height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>My Dashboard</h2>
        <p>Welcome back, <strong><?= htmlspecialchars($_SESSION['name']) ?></strong>! Here are your violation records.</p>
    </div>

    <!-- Student Info Card -->
    <div class="student-card">
        <div class="student-card-header">
            <div class="student-avatar">🎓</div>
            <div>
                <div class="student-card-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="student-card-no"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <span class="student-card-badge">
                <?= htmlspecialchars($student['course']) ?> — Year <?= $student['year_level'] ?>
            </span>
        </div>
        <div class="student-info-grid">
            <div class="student-info-item">
                <div class="student-info-label">Student No.</div>
                <div class="student-info-value"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <div class="student-info-item">
                <div class="student-info-label">Full Name</div>
                <div class="student-info-value"><?= htmlspecialchars($student['name']) ?></div>
            </div>
            <div class="student-info-item">
                <div class="student-info-label">Course</div>
                <div class="student-info-value"><?= htmlspecialchars($student['course']) ?></div>
            </div>
            <div class="student-info-item">
                <div class="student-info-label">Year Level</div>
                <div class="student-info-value">Year <?= $student['year_level'] ?></div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f0fe;">📋</div>
            <div class="stat-info">
                <div class="stat-num"><?= $total ?></div>
                <div class="stat-label">Total Violations</div>
            </div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon" style="background:#fee2e2;">⚠️</div>
            <div class="stat-info">
                <div class="stat-num"><?= $pending ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon" style="background:#d1fae5;">✅</div>
            <div class="stat-info">
                <div class="stat-num"><?= $resolved ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Violations Table -->
    <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:0.8rem;">
            <div class="card-title" style="margin:0; border:none; padding:0;">My Violation Records</div>
            <input type="text" id="searchInput" class="form-control"
                   placeholder="🔍 Search violations..."
                   oninput="searchTable()"
                   style="max-width:280px; margin:0;">
        </div>
        <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

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
        <p id="noResults" style="display:none; text-align:center; color:var(--muted); padding:1rem;">
            No violations match your search.
        </p>
        <?php endif; ?>
    </div>
</div>

<script>
function searchTable() {
    const input  = document.getElementById('searchInput').value.toLowerCase();
    const rows   = document.querySelectorAll('#violationsTable tbody tr');
    let visible  = 0;
    rows.forEach(row => {
        const match = row.innerText.toLowerCase().includes(input);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
}
</script>
</body>
</html>