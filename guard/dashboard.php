<?php
require_once '../includes/config.php';
requireLogin('guard');

$success = '';
$error   = '';

// Record a new violation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no     = trim($_POST['student_no'] ?? '');
    $violation_type = trim($_POST['violation_type'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $date_recorded  = $_POST['date_recorded'] ?? date('Y-m-d');

    if ($student_no && $violation_type && $date_recorded) {
        // Find student
        $stmt = $conn->prepare("SELECT id FROM students WHERE student_no = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();

        if ($found) {
            $stmt = $conn->prepare("INSERT INTO violations (student_id, violation_type, description, date_recorded, recorded_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param("isssi", $found['id'], $violation_type, $description, $date_recorded, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $success = "Violation recorded successfully!";
            } else {
                $error = "Failed to record violation.";
            }
        } else {
            $error = "Student No. not found.";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get all violations
$violations = $conn->query("
    SELECT v.*, s.name AS student_name, s.student_no, u.name AS recorded_by_name
    FROM violations v
    JOIN students s ON v.student_id = s.id
    JOIN users u ON v.recorded_by = u.id
    ORDER BY v.date_recorded DESC
");

$rows    = $violations->fetch_all(MYSQLI_ASSOC);
$total   = count($rows);
$pending = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));

// Get students for datalist
$students = $conn->query("SELECT student_no, name FROM students ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Guard Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>Guard Dashboard</h2>
        <p>Record and monitor student violations.</p>
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
            <div class="stat-num"><?= $total - $pending ?></div>
            <div class="stat-label">Resolved</div>
        </div>
    </div>

    <!-- Record Violation -->
    <div class="card">
        <div class="card-title">📋 Record a Violation</div>

        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Student No. <span style="color:red">*</span></label>
                    <input type="text" name="student_no" class="form-control" list="students-list" placeholder="e.g. C26-01-0001-MAN121" required>
                    <datalist id="students-list">
                        <?php while ($s = $students->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($s['student_no']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Violation Type <span style="color:red">*</span></label>
                    <select name="violation_type" class="form-control" required>
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
                    <label>Date <span style="color:red">*</span></label>
                    <input type="date" name="date_recorded" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" class="form-control" placeholder="Optional details...">
                </div>
            </div>
            <button type="submit" class="btn btn-accent">Record Violation</button>
        </form>
    </div>

    <!-- All Violations -->
    <div class="card">
        <div class="card-title">📂 All Violations</div>
        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <p>No violations recorded yet.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student No.</th>
                        <th>Student Name</th>
                        <th>Violation</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $v): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><code><?= htmlspecialchars($v['student_no']) ?></code></td>
                        <td><?= htmlspecialchars($v['student_name']) ?></td>
                        <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                        <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                        <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
