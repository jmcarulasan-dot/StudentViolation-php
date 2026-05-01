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
$result = $stmt->get_result();

$total = $result->num_rows;
$result->data_seek(0);
$pending = 0; $resolved = 0; $rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    if ($row['status'] === 'pending')  $pending++;
    if ($row['status'] === 'resolved') $resolved++;
}

$latest = $rows[0] ?? null;

$thisMonth = 0;
foreach ($rows as $r) {
    if (date('Y-m', strtotime($r['date_recorded'])) === date('Y-m')) $thisMonth++;
}

$score = max(0, 100 - ($pending * 10) - ($resolved * 5));
if ($score >= 90)     { $standingLabel = 'Excellent'; $standingColor = '#2ecc71'; $standingBg = '#d1fae5'; }
elseif ($score >= 70) { $standingLabel = 'Good';      $standingColor = '#3b82f6'; $standingBg = '#dbeafe'; }
elseif ($score >= 50) { $standingLabel = 'Fair';      $standingColor = '#f0a500'; $standingBg = '#fef3c7'; }
else                   { $standingLabel = 'At Risk';   $standingColor = '#e84545'; $standingBg = '#fee2e2'; }

$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i months"));
    $monthlyData[$label] = 0;
}
foreach ($rows as $r) {
    $label = date('M Y', strtotime($r['date_recorded']));
    if (isset($monthlyData[$label])) $monthlyData[$label]++;
}

$typeData = [];
foreach ($rows as $r) {
    $t = $r['violation_type'];
    $typeData[$t] = ($typeData[$t] ?? 0) + 1;
}
arsort($typeData);

// Profile photo
$photoFile = $student['profile_photo'] ?? '';
$photoUrl  = $photoFile ? BASE_URL . 'uploads/profile/' . htmlspecialchars($photoFile) : '';

// Pending appeals count
$pendingAppeals = count(array_filter($rows, fn($r) => ($r['appeal_status'] ?? 'none') === 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — My Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        @media print {
            .navbar, .no-print { display: none !important; }
            .page-wrapper { margin: 0; padding: 1rem; }
            body { background: white; }
        }

        .standing-card {
            border-radius: var(--radius); padding: 1.2rem 1.4rem;
            margin-bottom: 1.4rem; display: flex; align-items: center;
            gap: 1rem; border: 2px solid; flex-wrap: wrap;
        }
        .standing-ring {
            width: 64px; height: 64px; border-radius: 50%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-weight: 800; flex-shrink: 0; border: 3px solid;
        }
        .standing-score { font-size: 1.3rem; line-height: 1; }
        .standing-pts   { font-size: .6rem; text-transform: uppercase; letter-spacing: .5px; }
        .standing-info h3 { font-size: 1rem; font-weight: 800; margin-bottom: 3px; }
        .standing-info p  { font-size: .82rem; margin: 0; opacity: .8; }

        .alert-banner {
            border-radius: var(--radius); padding: 1rem 1.2rem;
            margin-bottom: 1.4rem; display: flex; align-items: center;
            gap: .9rem; border: 1.5px solid #fecaca; background: #fef2f2;
        }
        .alert-banner-icon { font-size: 1.5rem; flex-shrink: 0; }
        .alert-banner-text strong { font-size: .9rem; color: #991b1b; display: block; margin-bottom: 2px; }
        .alert-banner-text span   { font-size: .8rem; color: #c53030; }

        /* Profile header */
        .student-profile-header {
            display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap;
        }
        .student-avatar-photo {
            width: 52px; height: 52px; border-radius: 50%;
            object-fit: cover; border: 2.5px solid rgba(255,255,255,.4);
            flex-shrink: 0;
        }
        .student-avatar-placeholder {
            width: 52px; height: 52px; border-radius: 50%;
            background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; flex-shrink: 0;
        }

        /* Appeal badge on table */
        .appeal-badge {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
            margin-left: 5px;
        }
        .appeal-none     { background:#f1f5f9; color:#64748b; }
        .appeal-pending  { background:#fef3c7; color:#92400e; }
        .appeal-approved { background:#d1fae5; color:#065f46; }
        .appeal-rejected { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">

    <!-- Header -->
    <div class="page-header no-print" style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
        <div>
            <h2>My Dashboard</h2>
            <p>Welcome back, <strong><?= htmlspecialchars($_SESSION['name']) ?></strong>! Here's your complete violation summary.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>student/profile.php" class="btn btn-outline no-print">👤 My Profile</a>
            <button onclick="window.print()" class="btn btn-outline no-print">🖨️ Print Report</button>
        </div>
    </div>

    <!-- Pending violation alert -->
    <?php if ($latest && $latest['status'] === 'pending'): ?>
    <div class="alert-banner no-print">
        <div class="alert-banner-icon">🚨</div>
        <div class="alert-banner-text">
            <strong>You have a pending violation that needs attention</strong>
            <span>
                Latest: <strong><?= htmlspecialchars($latest['violation_type']) ?></strong>
                recorded on <?= date('F d, Y', strtotime($latest['date_recorded'])) ?>
                by <?= htmlspecialchars($latest['recorded_by_name']) ?>.
                Please coordinate with the guidance office or
                <a href="<?= BASE_URL ?>student/profile.php#appeals" style="color:#c53030; font-weight:700;">file an appeal</a>.
            </span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending appeals alert -->
    <?php if ($pendingAppeals > 0): ?>
    <div class="alert alert-info no-print" style="margin-bottom:1.4rem;">
        ⏳ You have <strong><?= $pendingAppeals ?></strong> appeal<?= $pendingAppeals > 1 ? 's' : '' ?> awaiting review by the guidance office.
    </div>
    <?php endif; ?>

    <!-- Student Info Card (with profile photo) -->
    <div class="student-card">
        <div class="student-card-header">
            <!-- Photo or placeholder -->
            <?php if ($photoUrl): ?>
                <img src="<?= $photoUrl ?>?v=<?= time() ?>" alt="Profile" class="student-avatar-photo">
            <?php else: ?>
                <div class="student-avatar-placeholder">🎓</div>
            <?php endif; ?>
            <div>
                <div class="student-card-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="student-card-no"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <span class="student-card-badge">
                <?= htmlspecialchars($student['course']) ?> — Year <?= $student['year_level'] ?>
            </span>
            <a href="<?= BASE_URL ?>student/profile.php" class="btn btn-sm no-print"
               style="background:rgba(255,255,255,.12); color:white; border:1px solid rgba(255,255,255,.2); margin-left:auto;">
                ✏️ Edit Profile
            </a>
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

    <!-- Standing Card -->
    <div class="standing-card no-print"
         style="background:<?= $standingBg ?>; border-color:<?= $standingColor ?>44; color:<?= $standingColor ?>;">
        <div class="standing-ring" style="background:<?= $standingColor ?>22; border-color:<?= $standingColor ?>;">
            <span class="standing-score"><?= $score ?></span>
            <span class="standing-pts">pts</span>
        </div>
        <div class="standing-info">
            <h3>Standing: <?= $standingLabel ?></h3>
            <p>
                <?php if ($score >= 90): ?>
                    Great job! You have no or very few violations. Keep it up.
                <?php elseif ($score >= 70): ?>
                    You're in good standing. Resolve pending violations to improve your score.
                <?php elseif ($score >= 50): ?>
                    Your standing needs attention. Visit the guidance office to resolve violations.
                <?php else: ?>
                    ⚠️ Your standing is at risk. Immediate action required — see the guidance office.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($thisMonth > 0): ?>
        <div style="margin-left:auto; text-align:center; flex-shrink:0;">
            <div style="font-size:1.4rem; font-weight:800;"><?= $thisMonth ?></div>
            <div style="font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; opacity:.8;">This Month</div>
        </div>
        <?php endif; ?>
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
        <div class="stat-card" style="">
            <div class="stat-icon" style="background:#ede9fe;">📅</div>
            <div class="stat-info">
                <div class="stat-num" style="color:#8b5cf6;"><?= $thisMonth ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <?php if ($total > 0): ?>
    <div class="g2e no-print" style="margin-bottom:1.4rem;">
        <div class="chart-card">
            <div class="chart-title"><span class="cdot" style="background:#1a3a5c;"></span>My Violations — Last 6 Months</div>
            <div class="cwrap"><canvas id="myLineChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-title"><span class="cdot" style="background:#e84545;"></span>By Violation Type</div>
            <div class="cwrap"><canvas id="myTypeChart"></canvas></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Violations Table -->
    <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.8rem;">
            <div class="card-title" style="margin:0; border:none; padding:0;">My Violation Records</div>
            <?php if ($total > 0): ?>
            <div class="no-print" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <select id="filterStatus" onchange="filterTable()" class="form-control"
                        style="max-width:140px; margin:0; padding:8px 12px;">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="resolved">Resolved</option>
                </select>
                <input type="text" id="searchInput" class="form-control"
                       placeholder="🔍 Search..." oninput="filterTable()"
                       style="max-width:200px; margin:0;">
                <a href="<?= BASE_URL ?>student/profile.php" class="btn btn-outline btn-sm no-print">
                    📋 Manage Appeals
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div style="height:2px; background:var(--border); margin-bottom:1rem; border-radius:2px;"></div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-icon">🎉</div>
                <p>No violations on record — keep it up!</p>
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
                        <th class="no-print">Appeal</th>
                        <th class="no-print">Guidance Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $v):
                        $appealStatus  = $v['appeal_status']  ?? 'none';
                        $appealRemarks = $v['appeal_remarks']  ?? '';
                    ?>
                    <tr data-status="<?= $v['status'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($v['description'] ?? '—') ?></td>
                        <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                        <td><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                        <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                        <td class="no-print">
                            <?php if ($v['status'] === 'pending' && $appealStatus === 'none'): ?>
                                <a href="<?= BASE_URL ?>student/profile.php"
                                   class="btn btn-sm btn-outline" style="font-size:.73rem;">
                                    📝 Appeal
                                </a>
                            <?php else: ?>
                                <span class="appeal-badge appeal-<?= htmlspecialchars($appealStatus) ?>">
                                    <?= $appealStatus === 'none' ? '—' : ucfirst($appealStatus) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="no-print">
                            <?php if ($appealRemarks && $appealStatus !== 'none'): ?>
                                <div style="
                                    font-size:.78rem; line-height:1.45; max-width:200px;
                                    padding:5px 8px; border-radius:7px;
                                    <?= $appealStatus === 'approved'
                                        ? 'background:#d1fae5; color:#065f46; border:1px solid #6ee7b7;'
                                        : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;' ?>
                                ">
                                    <?= htmlspecialchars($appealRemarks) ?>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--muted); font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p id="noResults" style="display:none; text-align:center; color:var(--muted); padding:1rem; font-size:.88rem;">
            No records match your search.
        </p>
        <?php endif; ?>
    </div>

    <!-- Print footer -->
    <div style="display:none;" class="print-only">
        <p style="font-size:.8rem; color:#666; margin-top:2rem; text-align:center;">
            Printed from ACLC SVS — Student Violation System · <?= date('F d, Y h:i A') ?>
        </p>
    </div>
</div>

<style>
@media print { .print-only { display:block !important; } }
</style>

<script>
<?php if ($total > 0): ?>
Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

new Chart(document.getElementById('myLineChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_keys($monthlyData)) ?>,
        datasets: [{ label:'Violations', data:<?= json_encode(array_values($monthlyData)) ?>,
            borderColor:'#1a3a5c', backgroundColor:'rgba(26,58,92,0.08)',
            borderWidth:2.5, pointBackgroundColor:'#1a3a5c',
            pointRadius:5, pointHoverRadius:7, fill:true, tension:0.4 }]
    },
    options: { responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{ y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}, x:{grid:{display:false}} }
    }
});

new Chart(document.getElementById('myTypeChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($typeData)) ?>,
        datasets:[{ data:<?= json_encode(array_values($typeData)) ?>,
            backgroundColor:['#1a3a5c','#e84545','#f0a500','#8b5cf6','#2ecc71','#3b82f6','#14b8a6'],
            borderWidth:2, borderColor:'#fff', hoverOffset:5 }]
    },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'60%',
        plugins:{ legend:{position:'bottom', labels:{padding:12,usePointStyle:true,pointStyleWidth:10,font:{size:11}}} }
    }
});
<?php endif; ?>

function filterTable() {
    const q      = document.getElementById('searchInput')?.value.toLowerCase() ?? '';
    const status = document.getElementById('filterStatus')?.value ?? '';
    let vis = 0;
    document.querySelectorAll('#violationsTable tbody tr').forEach(r => {
        const show = r.innerText.toLowerCase().includes(q) && (!status || r.dataset.status === status);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    const nr = document.getElementById('noResults');
    if (nr) nr.style.display = vis === 0 ? 'block' : 'none';
}
</script>
</body>
</html>