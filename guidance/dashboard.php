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
        $sid = intval($_POST['student_id']);
        $conn->query("DELETE FROM violations WHERE student_id = $sid");
        $conn->query("DELETE FROM users WHERE student_id = $sid");
        $conn->query("DELETE FROM students WHERE id = $sid");
        $success = "Student and all related records deleted.";
    }
}

$students   = $conn->query("SELECT * FROM students ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$violations = $conn->query("
    SELECT v.*, s.name AS student_name, s.student_no, u.name AS recorded_by_name
    FROM violations v
    JOIN students s ON v.student_id = s.id
    JOIN users u ON v.recorded_by = u.id
    ORDER BY v.date_recorded DESC
")->fetch_all(MYSQLI_ASSOC);

$totalStudents   = count($students);
$totalViolations = count($violations);
$pendingCount    = count(array_filter($violations, fn($v) => $v['status'] === 'pending'));
$resolvedCount   = $totalViolations - $pendingCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — Guidance Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.45);
            z-index: 999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .modal-overlay.active { display: flex; }
        .action-form { display: inline; }
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
                    <option>Late</option>
                    <option>Cutting Class</option>
                    <option>Improper Uniform</option>
                    <option>Disruptive Behavior</option>
                    <option>Vandalism</option>
                    <option>Prohibited Items</option>
                    <option>Other</option>
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
        <p>Full control over students and violation records.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f0fe;">👥</div>
            <div class="stat-info">
                <div class="stat-num"><?= $totalStudents ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="stat-card gold">
            <div class="stat-icon" style="background:#fef3c7;">📋</div>
            <div class="stat-info">
                <div class="stat-num"><?= $totalViolations ?></div>
                <div class="stat-label">Total Violations</div>
            </div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon" style="background:#fee2e2;">⚠️</div>
            <div class="stat-info">
                <div class="stat-num"><?= $pendingCount ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon" style="background:#d1fae5;">✅</div>
            <div class="stat-info">
                <div class="stat-num"><?= $resolvedCount ?></div>
                <div class="stat-label">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('violations', this)">📂 Violations</button>
        <button class="tab-btn" onclick="switchTab('students', this)">👥 Students</button>
        <button class="tab-btn" onclick="switchTab('add-student', this)">➕ Add Student</button>
    </div>

    <!-- Violations Tab -->
    <div id="tab-violations" class="tab-content active">
        <div class="card">
            <div class="card-title">All Violation Records</div>
            <?php if (empty($violations)): ?>
                <div class="empty-state"><div class="empty-icon">📂</div><p>No violations yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student No.</th>
                            <th>Student</th>
                            <th>Violation</th>
                            <th>Date</th>
                            <th>Recorded By</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $i => $v): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><code style="font-size:0.82rem; color:var(--primary);"><?= htmlspecialchars($v['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($v['student_name']) ?></td>
                            <td><strong><?= htmlspecialchars($v['violation_type']) ?></strong></td>
                            <td><?= date('M d, Y', strtotime($v['date_recorded'])) ?></td>
                            <td><?= htmlspecialchars($v['recorded_by_name']) ?></td>
                            <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
                            <td style="display:flex; gap:5px; flex-wrap:wrap;">
                                <button class="btn btn-sm btn-outline" onclick="openEdit(
                                    <?= $v['id'] ?>,
                                    '<?= addslashes($v['violation_type']) ?>',
                                    '<?= addslashes($v['description'] ?? '') ?>',
                                    '<?= $v['date_recorded'] ?>',
                                    '<?= $v['status'] ?>'
                                )">Edit</button>
                                <form class="action-form" method="POST">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $v['status'] === 'pending' ? 'resolved' : 'pending' ?>">
                                    <button type="submit" class="btn btn-sm <?= $v['status'] === 'pending' ? 'btn-success' : 'btn-outline' ?>">
                                        <?= $v['status'] === 'pending' ? 'Resolve' : 'Reopen' ?>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Students Tab -->
    <div id="tab-students" class="tab-content">
        <div class="card">
            <div class="card-title">All Students</div>
            <?php if (empty($students)): ?>
                <div class="empty-state"><div class="empty-icon">👥</div><p>No students yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student No.</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Year Level</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><code style="font-size:0.82rem; color:var(--primary);"><?= htmlspecialchars($s['student_no']) ?></code></td>
                            <td><?= htmlspecialchars($s['name']) ?></td>
                            <td><?= htmlspecialchars($s['course']) ?></td>
                            <td>Year <?= $s['year_level'] ?></td>
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Student Tab -->
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
                            <option>BSIT</option>
                            <option>BSCS</option>
                            <option>BSA</option>
                            <option>BSBA</option>
                            <option>BSHM</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year Level <span style="color:var(--accent)">*</span></label>
                        <select name="year_level" class="form-control" required>
                            <option value="">-- Select Year --</option>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
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
</div>

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
}

function openEdit(id, type, description, date, status) {
    document.getElementById('edit_violation_id').value   = id;
    document.getElementById('edit_violation_type').value = type;
    document.getElementById('edit_description').value    = description;
    document.getElementById('edit_date').value           = date;
    document.getElementById('edit_status').value         = status;
    document.getElementById('editModal').classList.add('active');
}

function closeModal() {
    document.getElementById('editModal').classList.remove('active');
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>