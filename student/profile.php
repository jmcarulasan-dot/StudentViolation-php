<?php
require_once '../includes/config.php';
requireLogin('student');

$student_id = $_SESSION['student_id'];
$success = '';
$error   = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 0);

    if ($name && $course && $year_level) {
        // Update students table
        $stmt = $conn->prepare("UPDATE students SET name = ?, course = ?, year_level = ? WHERE id = ?");
        $stmt->bind_param("ssii", $name, $course, $year_level, $student_id);
        if ($stmt->execute()) {
            // Update users name too
            $stmt2 = $conn->prepare("UPDATE users SET name = ? WHERE student_id = ?");
            $stmt2->bind_param("si", $name, $student_id);
            $stmt2->execute();
            $_SESSION['name'] = $name;
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

// Get student info
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — My Profile</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>My Profile</h2>
        <p>View and update your personal information.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

    <div class="card">
        <div class="card-title">👤 Personal Information</div>

        <!-- Profile Info Display -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:1rem; margin-bottom:2rem; padding:1rem; background:var(--bg); border-radius:10px;">
            <div>
                <div style="font-size:0.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Student No.</div>
                <div style="font-weight:700; margin-top:4px; color:var(--primary);"><?= htmlspecialchars($student['student_no']) ?></div>
            </div>
            <div>
                <div style="font-size:0.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Full Name</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['name']) ?></div>
            </div>
            <div>
                <div style="font-size:0.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Course</div>
                <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['course']) ?></div>
            </div>
            <div>
                <div style="font-size:0.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px;">Year Level</div>
                <div style="font-weight:700; margin-top:4px;">Year <?= $student['year_level'] ?></div>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="card-title">✏️ Update Information</div>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" class="form-control"
                        value="<?= htmlspecialchars($student['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Course *</label>
                    <select name="course" class="form-control" required>
                        <?php
                        $courses = ['BSIT','BSCS','BSEMC','BSA','BSBA','BSHM'];
                        foreach ($courses as $c) {
                            $sel = ($student['course'] === $c) ? 'selected' : '';
                            echo "<option value=\"$c\" $sel>$c</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="form-group" style="max-width:200px;">
                <label>Year Level *</label>
                <select name="year_level" class="form-control" required>
                    <?php for ($y = 1; $y <= 4; $y++): ?>
                        <option value="<?= $y ?>" <?= $student['year_level'] == $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="display:flex; gap:8px; margin-top:0.5rem;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= BASE_URL ?>student/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>