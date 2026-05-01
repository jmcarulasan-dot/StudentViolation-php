<?php
require_once '../includes/config.php';
requireLogin('student');

$student_id = $_SESSION['student_id'];
$success    = '';
$error      = '';
$pw_success = '';
$pw_error   = '';

// ── Handle profile picture upload ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['profile_photo'];
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize  = 3 * 1024 * 1024; // 3MB

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowed)) {
            $error = "Only JPG, PNG, GIF, or WEBP images are allowed.";
        } elseif ($file['size'] > $maxSize) {
            $error = "Image must be under 3MB.";
        } else {
            $uploadDir = '../uploads/profile/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $student_id . '_' . time() . '.' . strtolower($ext);
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                // Delete old photo if exists
                $stmt = $conn->prepare("SELECT profile_photo FROM students WHERE id = ?");
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                if (!empty($old['profile_photo'])) {
                    $oldPath = '../uploads/profile/' . $old['profile_photo'];
                    if (file_exists($oldPath)) unlink($oldPath);
                }

                $stmt = $conn->prepare("UPDATE students SET profile_photo = ? WHERE id = ?");
                $stmt->bind_param("si", $filename, $student_id);
                $stmt->execute()
                    ? $success = "Profile picture updated!"
                    : $error   = "Failed to save photo.";
            } else {
                $error = "Failed to upload image. Check folder permissions.";
            }
        }
    } else {
        $error = "Please select an image file.";
    }
}

// ── Handle profile update (name only now) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name) {
        $stmt = $conn->prepare("UPDATE students SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $student_id);
        if ($stmt->execute()) {
            $stmt2 = $conn->prepare("UPDATE users SET name = ? WHERE student_id = ?");
            $stmt2->bind_param("si", $name, $student_id);
            $stmt2->execute();
            $_SESSION['name'] = $name;
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile.";
        }
    } else {
        $error = "Name cannot be empty.";
    }
}

// ── Handle password change ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!password_verify($current, $user['password'])) {
        $pw_error = "Current password is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $pw_error = "New password must be at least 8 characters.";
    } elseif ($new_pw !== $confirm) {
        $pw_error = "New passwords do not match.";
    } else {
        $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE student_id = ?");
        $stmt->bind_param("si", $hashed, $student_id);
        $stmt->execute()
            ? $pw_success = "Password changed successfully!"
            : $pw_error   = "Failed to update password.";
    }
}

// ── Handle appeal submission ───────────────────────────────
$appeal_success = '';
$appeal_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appeal'])) {
    $violation_id = intval($_POST['violation_id'] ?? 0);
    $appeal_text  = trim($_POST['appeal_text'] ?? '');

    if (!$violation_id || !$appeal_text) {
        $appeal_error = "Please provide your appeal reason.";
    } else {
        // Verify violation belongs to this student and is pending
        $stmt = $conn->prepare("SELECT id, status, appeal_status FROM violations WHERE id = ? AND student_id = ?");
        $stmt->bind_param("ii", $violation_id, $student_id);
        $stmt->execute();
        $vRow = $stmt->get_result()->fetch_assoc();

        if (!$vRow) {
            $appeal_error = "Violation not found.";
        } elseif ($vRow['status'] !== 'pending') {
            $appeal_error = "You can only appeal pending violations.";
        } elseif (!empty($vRow['appeal_status']) && $vRow['appeal_status'] === 'pending') {
            $appeal_error = "You have already submitted an appeal for this violation.";
        } else {
            // Add appeal_text and appeal_status columns if they don't exist (safe migration)
            $conn->query("ALTER TABLE violations ADD COLUMN IF NOT EXISTS appeal_text TEXT NULL");
            $conn->query("ALTER TABLE violations ADD COLUMN IF NOT EXISTS appeal_status VARCHAR(20) DEFAULT 'none'");

            $stmt = $conn->prepare("UPDATE violations SET appeal_text = ?, appeal_status = 'pending' WHERE id = ? AND student_id = ?");
            $stmt->bind_param("sii", $appeal_text, $violation_id, $student_id);
            $stmt->execute()
                ? $appeal_success = "Your appeal has been submitted successfully!"
                : $appeal_error   = "Failed to submit appeal.";
        }
    }
}

// ── Get student info ───────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// ── Get violations for appeal section ─────────────────────
$stmt = $conn->prepare("
    SELECT v.*, u.name AS recorded_by_name
    FROM violations v
    JOIN users u ON v.recorded_by = u.id
    WHERE v.student_id = ?
    ORDER BY v.date_recorded DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$violations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pendingViolations = array_filter($violations, fn($v) => $v['status'] === 'pending');

$keepPwOpen = ($pw_error || $pw_success) ? 'true' : 'false';

// Build profile photo URL
$photoFile = $student['profile_photo'] ?? '';
$photoUrl  = $photoFile ? BASE_URL . 'uploads/profile/' . htmlspecialchars($photoFile) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SVS — My Profile</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        /* ── Profile Picture ── */
        .photo-wrap {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.8rem;
            flex-wrap: wrap;
        }
        .photo-ring {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }
        .photo-ring img, .photo-ring .photo-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            box-shadow: 0 4px 16px rgba(26,58,92,0.15);
        }
        .photo-placeholder {
            background: linear-gradient(135deg, #1a3a5c, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            color: white;
        }
        .photo-edit-btn {
            position: absolute;
            bottom: 0; right: 0;
            width: 30px; height: 30px;
            background: var(--primary);
            border-radius: 50%;
            border: 2px solid white;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: .75rem;
            color: white;
            transition: background .2s;
        }
        .photo-edit-btn:hover { background: var(--primary-dark); }
        .photo-info { flex: 1; }
        .photo-info strong { font-size: 1.1rem; font-weight: 800; color: var(--primary); display: block; }
        .photo-info span { font-size: .83rem; color: var(--muted); margin-top: 3px; display: block; }

        /* Upload form hidden trigger */
        #photoFileInput { display: none; }
        .photo-upload-bar {
            display: none;
            margin-top: .8rem;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: .8rem 1rem;
            gap: .7rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .photo-upload-bar.show { display: flex; }
        .photo-preview-img {
            width: 52px; height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
            display: none;
        }

        /* ── Readonly field style ── */
        .field-locked {
            width: 100%; padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 9px;
            font-size: .88rem;
            color: var(--muted);
            background: #f1f5f9;
            cursor: not-allowed;
        }
        .lock-note {
            font-size: .73rem;
            color: var(--muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ── Appeal section ── */
        .appeal-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
        }
        .appeal-item:last-child { border-bottom: none; }
        .appeal-violation-name { font-size: .9rem; font-weight: 700; color: var(--text); }
        .appeal-meta { font-size: .78rem; color: var(--muted); margin-top: 2px; }
        .appeal-status-badge {
            display: inline-block; padding: 2px 9px;
            border-radius: 20px; font-size: .68rem;
            font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
            margin-left: 6px;
        }
        .appeal-status-none     { background: #f1f5f9; color: #64748b; }
        .appeal-status-pending  { background: #fef3c7; color: #92400e; }
        .appeal-status-approved { background: #d1fae5; color: #065f46; }
        .appeal-status-rejected { background: #fee2e2; color: #991b1b; }

        .appeal-form-inline {
            margin-top: .7rem;
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 9px;
            padding: .8rem 1rem;
        }

        /* Tabs */
        .profile-tabs { display: flex; gap: 0; margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border); overflow-x: auto; }
        .profile-tab-btn {
            padding: 11px 20px; border: none; border-bottom: 3px solid transparent;
            margin-bottom: -2px; background: transparent; font-family: var(--font);
            font-weight: 700; font-size: .83rem; cursor: pointer; color: var(--muted);
            transition: all .18s; white-space: nowrap;
        }
        .profile-tab-btn:hover:not(.active) { color: var(--primary); background: rgba(26,58,92,.04); }
        .profile-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .profile-tab-content { display: none; }
        .profile-tab-content.active { display: block; }
    </style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h2>My Profile</h2>
        <p>Manage your account settings and violation appeals.</p>
    </div>

    <!-- Global alerts -->
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($appeal_success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($appeal_success) ?></div><?php endif; ?>
    <?php if ($appeal_error):   ?><div class="alert alert-error">❌ <?= htmlspecialchars($appeal_error) ?></div><?php endif; ?>

    <!-- ── Profile Photo Card ── -->
    <div class="card">
        <div class="photo-wrap">
            <!-- Avatar ring -->
            <div class="photo-ring">
                <?php if ($photoUrl): ?>
                    <img src="<?= $photoUrl ?>?v=<?= time() ?>" alt="Profile Photo" id="currentPhoto">
                <?php else: ?>
                    <div class="photo-placeholder" id="currentPhoto">🎓</div>
                <?php endif; ?>
                <div class="photo-edit-btn" onclick="triggerPhotoUpload()" title="Change photo">✏️</div>
            </div>
            <!-- Name and ID -->
            <div class="photo-info">
                <strong><?= htmlspecialchars($student['name']) ?></strong>
                <span><?= htmlspecialchars($student['student_no']) ?></span>
                <span style="margin-top:5px;">
                    <span class="badge badge-student"><?= htmlspecialchars($student['course']) ?> — Year <?= $student['year_level'] ?></span>
                </span>
            </div>
        </div>

        <!-- Hidden file input -->
        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="previewPhoto(this)">

        <!-- Upload bar (shown after choosing file) -->
        <div class="photo-upload-bar" id="photoUploadBar">
            <img id="photoPreviewImg" class="photo-preview-img" alt="Preview">
            <div style="flex:1;">
                <div style="font-size:.84rem; font-weight:700; color:var(--text);" id="photoFileName">No file chosen</div>
                <div style="font-size:.74rem; color:var(--muted);">JPG, PNG, GIF, WEBP · Max 3MB</div>
            </div>
            <form method="POST" enctype="multipart/form-data" id="photoForm">
                <input type="hidden" name="upload_photo" value="1">
                <input type="file" name="profile_photo" id="hiddenPhotoInput" style="display:none">
                <div style="display:flex; gap:7px;">
                    <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="cancelPhotoUpload()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Tabs ── -->
    <div class="profile-tabs">
        <button class="profile-tab-btn active" id="ptab-info" onclick="switchProfileTab('info', this)">👤 My Information</button>
        <button class="profile-tab-btn" id="ptab-password" onclick="switchProfileTab('password', this)">🔒 Change Password</button>
        <button class="profile-tab-btn" id="ptab-appeals" onclick="switchProfileTab('appeals', this)">
            📋 My Appeals
            <?php $pendingAppeals = array_filter($violations, fn($v) => !empty($v['appeal_status']) && $v['appeal_status'] === 'pending'); ?>
            <?php if (count($pendingAppeals) > 0): ?>
                <span style="background:var(--accent);color:#fff;border-radius:10px;padding:1px 7px;font-size:.67rem;margin-left:4px;"><?= count($pendingAppeals) ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ══ TAB: MY INFORMATION ══ -->
    <div class="profile-tab-content active" id="ptab-content-info">
        <div class="card">
            <div class="card-title">👤 Personal Information</div>

            <!-- Read-only info display -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
                        gap:1rem; margin-bottom:2rem; padding:1rem;
                        background:var(--bg); border-radius:10px;">
                <div>
                    <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Student No.</div>
                    <div style="font-weight:700; margin-top:4px; color:var(--primary);"><?= htmlspecialchars($student['student_no']) ?></div>
                </div>
                <div>
                    <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Full Name</div>
                    <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['name']) ?></div>
                </div>
                <div>
                    <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Course</div>
                    <div style="font-weight:700; margin-top:4px;"><?= htmlspecialchars($student['course']) ?></div>
                </div>
                <div>
                    <div style="font-size:.78rem; color:var(--muted); text-transform:uppercase; letter-spacing:.5px;">Year Level</div>
                    <div style="font-weight:700; margin-top:4px;">Year <?= $student['year_level'] ?></div>
                </div>
            </div>

            <!-- Editable: Name only -->
            <div class="card-title" style="font-size:.83rem;">✏️ Update Name</div>
            <form method="POST" style="max-width:480px;">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($student['name']) ?>" required>
                </div>

                <!-- Locked fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:5px;">
                            Course <span style="font-size:.75rem; color:var(--muted);">🔒 locked</span>
                        </label>
                        <div class="field-locked"><?= htmlspecialchars($student['course']) ?></div>
                        <div class="lock-note">🔒 Contact guidance office to change your course.</div>
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:5px;">
                            Year Level <span style="font-size:.75rem; color:var(--muted);">🔒 locked</span>
                        </label>
                        <div class="field-locked">Year <?= $student['year_level'] ?></div>
                        <div class="lock-note">🔒 Contact guidance office to change your year.</div>
                    </div>
                </div>

                <div style="display:flex; gap:8px; margin-top:.5rem; flex-wrap:wrap;">
                    <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    <a href="<?= BASE_URL ?>student/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ TAB: CHANGE PASSWORD ══ -->
    <div class="profile-tab-content" id="ptab-content-password">
        <div class="card" style="max-width:500px;">
            <div class="card-title">🔒 Change Password</div>

            <?php if ($pw_success): ?><div class="alert alert-success">✅ <?= $pw_success ?></div><?php endif; ?>
            <?php if ($pw_error):   ?><div class="alert alert-error">❌ <?= $pw_error ?></div><?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password * <span style="color:var(--muted); font-size:.8rem;">(min. 8 characters)</span></label>
                    <input type="password" name="new_password" id="new_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password *</label>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="form-control" oninput="checkMatch()" required>
                    <small id="matchMsg" style="display:none; margin-top:4px; font-size:.8rem;"></small>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
            </form>
        </div>
    </div>

    <!-- ══ TAB: APPEALS ══ -->
    <div class="profile-tab-content" id="ptab-content-appeals">
        <div class="card">
            <div class="card-title">📋 Violation Appeals</div>

            <?php if (empty($violations)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🎉</div>
                    <p>You have no violations to appeal.</p>
                </div>
            <?php else: ?>
                <p style="font-size:.85rem; color:var(--muted); margin-bottom:1.2rem;">
                    You can submit an appeal for <strong>pending</strong> violations. Resolved violations cannot be appealed.
                </p>

                <?php foreach ($violations as $v):
                    $appealStatus  = $v['appeal_status'] ?? 'none';
                    $appealText    = $v['appeal_text'] ?? '';
                    $canAppeal     = $v['status'] === 'pending' && $appealStatus === 'none';
                    $alreadyFiled  = $v['status'] === 'pending' && $appealStatus === 'pending';
                ?>
                <div class="appeal-item">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                        <div>
                            <div class="appeal-violation-name">
                                <?= htmlspecialchars($v['violation_type']) ?>
                                <span class="appeal-status-badge appeal-status-<?= htmlspecialchars($appealStatus) ?>">
                                    <?= $appealStatus === 'none' ? 'No appeal' : ucfirst($appealStatus) ?>
                                </span>
                            </div>
                            <div class="appeal-meta">
                                Recorded by <?= htmlspecialchars($v['recorded_by_name']) ?>
                                · <?= date('M d, Y', strtotime($v['date_recorded'])) ?>
                                <?php if ($v['description']): ?>
                                    · <?= htmlspecialchars($v['description']) ?>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:5px;">
                                <span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span>
                            </div>
                        </div>

                        <?php if ($canAppeal): ?>
                            <button class="btn btn-outline btn-sm"
                                    onclick="toggleAppealForm(<?= $v['id'] ?>)">
                                📝 File Appeal
                            </button>
                        <?php elseif ($alreadyFiled): ?>
                            <span style="font-size:.8rem; color:#92400e; font-weight:600;">⏳ Awaiting review</span>
                        <?php elseif ($appealStatus === 'approved'): ?>
                            <span style="font-size:.8rem; color:#065f46; font-weight:600;">✅ Appeal approved</span>
                        <?php elseif ($appealStatus === 'rejected'): ?>
                            <span style="font-size:.8rem; color:#991b1b; font-weight:600;">❌ Appeal rejected</span>
                        <?php endif; ?>
                    </div>

                    <!-- Show existing appeal text if filed -->
                    <?php if ($appealText): ?>
                    <div style="margin-top:.7rem; background:#f8fafc; border-radius:8px; padding:.7rem .9rem; font-size:.82rem; color:var(--muted); border:1px solid var(--border);">
                        <strong style="color:var(--text);">Your appeal:</strong><br>
                        <?= nl2br(htmlspecialchars($appealText)) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Show guidance remarks if appeal was reviewed -->
                    <?php
                    $appealRemarks = $v['appeal_remarks'] ?? '';
                    if ($appealRemarks && in_array($appealStatus, ['approved','rejected'])):
                        $isApproved = $appealStatus === 'approved';
                    ?>
                    <div style="
                        margin-top:.6rem; border-radius:8px; padding:.7rem .9rem;
                        font-size:.82rem; line-height:1.5;
                        <?= $isApproved
                            ? 'background:#d1fae5; color:#065f46; border:1px solid #6ee7b7;'
                            : 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;' ?>
                    ">
                        <strong><?= $isApproved ? '✅ Guidance remarks (Approved):' : '❌ Guidance remarks (Rejected):' ?></strong><br>
                        <?= nl2br(htmlspecialchars($appealRemarks)) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Inline appeal form -->
                    <?php if ($canAppeal): ?>
                    <div class="appeal-form-inline" id="appeal-form-<?= $v['id'] ?>" style="display:none;">
                        <form method="POST">
                            <input type="hidden" name="submit_appeal" value="1">
                            <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                            <div class="form-group" style="margin-bottom:.7rem;">
                                <label style="font-size:.83rem; font-weight:700; color:var(--primary);">
                                    📝 Reason for Appeal *
                                </label>
                                <textarea name="appeal_text" class="form-control"
                                          rows="3" required
                                          placeholder="Explain why you are disputing this violation..."
                                          style="resize:vertical;"></textarea>
                            </div>
                            <div style="display:flex; gap:7px;">
                                <button type="submit" class="btn btn-primary btn-sm">Submit Appeal</button>
                                <button type="button" class="btn btn-outline btn-sm"
                                        onclick="toggleAppealForm(<?= $v['id'] ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div><!-- end .page-wrapper -->

<script>
// ── Profile Tabs ──────────────────────────────────────────
function switchProfileTab(name, btn) {
    document.querySelectorAll('.profile-tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.profile-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('ptab-content-' + name).classList.add('active');
    btn.classList.add('active');
}

// Keep password tab open on pw error/success
<?php if ($keepPwOpen === 'true'): ?>
switchProfileTab('password', document.getElementById('ptab-password'));
<?php endif; ?>

// ── Password match check ──────────────────────────────────
function checkMatch() {
    const np  = document.getElementById('new_password').value;
    const cp  = document.getElementById('confirm_password').value;
    const msg = document.getElementById('matchMsg');
    if (!cp) { msg.style.display = 'none'; return; }
    msg.style.display = 'block';
    if (np === cp) {
        msg.style.color = 'green';
        msg.textContent = '✅ Passwords match';
    } else {
        msg.style.color = 'red';
        msg.textContent = '❌ Passwords do not match';
    }
}

// ── Profile photo upload ──────────────────────────────────
function triggerPhotoUpload() {
    document.getElementById('photoFileInput').click();
}

function previewPhoto(input) {
    const file = input.files[0];
    if (!file) return;

    // Validate size client-side
    if (file.size > 3 * 1024 * 1024) {
        alert('Image must be under 3MB.');
        input.value = '';
        return;
    }

    const bar      = document.getElementById('photoUploadBar');
    const nameEl   = document.getElementById('photoFileName');
    const previewEl= document.getElementById('photoPreviewImg');
    const hiddenInput = document.getElementById('hiddenPhotoInput');

    // Transfer file to the actual form input via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(file);
    hiddenInput.files = dt.files;

    nameEl.textContent = file.name;
    bar.classList.add('show');

    const reader = new FileReader();
    reader.onload = e => {
        previewEl.src = e.target.result;
        previewEl.style.display = 'block';
    };
    reader.readAsDataURL(file);
}

function cancelPhotoUpload() {
    document.getElementById('photoFileInput').value = '';
    document.getElementById('hiddenPhotoInput').value = '';
    document.getElementById('photoUploadBar').classList.remove('show');
    document.getElementById('photoPreviewImg').style.display = 'none';
    document.getElementById('photoFileName').textContent = 'No file chosen';
}

// ── Appeal toggle ─────────────────────────────────────────
function toggleAppealForm(id) {
    const form = document.getElementById('appeal-form-' + id);
    if (!form) return;
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>
</body>
</html>