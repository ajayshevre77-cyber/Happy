


<?php
session_start();
require_once 'db.php';

// Authentication check
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'faculty') {
    header("Location: login.php?role=faculty");
    exit;
}

$user = $_SESSION['user'];
$db = get_db();

$current_faculty = null;
foreach ($db['faculty'] as $f) {
    if ($f['username'] === $user['username']) {
        $current_faculty = $f;
        break;
    }
}
if (!$current_faculty) {
    $current_faculty = [
        'username' => $user['username'],
        'name' => $user['name'],
        'email' => 'faculty@erp.edu',
        'phone' => '+91 99999 88888',
        'designation' => 'Assistant Professor',
        'workload' => '16 Hours / Week',
        'attendance' => '95%',
        'subjects' => '',
        'assigned_divisions' => 'A,B,C'
    ];
}
$faculty_subjects = array_map('trim', explode(',', $current_faculty['subjects'] ?? ''));
$faculty_divisions = array_map('trim', explode(',', $current_faculty['assigned_divisions'] ?? 'A,B,C'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_notifications') {
    $db['recent_activity'] = [];
    save_db($db);
    echo json_encode(['success' => true]);
    exit;
}

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle Approve / Reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
    $action = $_POST['action'];
    $leave_id = isset($_POST['leave_id']) ? intval($_POST['leave_id']) : 0;

    $updated = false;
    foreach ($db['leaves'] as &$leave) {
        if ($leave['id'] === $leave_id) {
            if ($action === 'approve') {
                $leave['status'] = 'Approved';
                $_SESSION['success_message'] = 'Leave request #' . $leave_id . ' (Reason: ' . $leave['reason'] . ') has been Approved.';
                $updated = true;
            } elseif ($action === 'reject') {
                $leave['status'] = 'Rejected';
                $_SESSION['success_message'] = 'Leave request #' . $leave_id . ' (Reason: ' . $leave['reason'] . ') has been Rejected.';
                $updated = true;
            }
            break;
        }
    }

    if ($updated) {
        save_db($db);
    } else {
        $_SESSION['error_message'] = 'Failed to update leave request status. Request #' . $leave_id . ' not found.';
    }
    header("Location: faculty_dashboard.php");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_notice') {
    $title = trim($_POST['title']);
    $desc = trim($_POST['desc']);
    $expiry = trim($_POST['expiry']);
    $file_name = '';

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file_name = basename($_FILES['attachment']['name']);
        if (!is_dir(__DIR__ . '/uploads')) { mkdir(__DIR__ . '/uploads', 0777, true); }
        move_uploaded_file($_FILES['attachment']['tmp_name'], __DIR__ . '/uploads/' . $file_name);
        $file_name = 'uploads/' . $file_name;
    }
    
    if (!empty($title) && !empty($desc)) {
        $max_notice_id = 0;
        foreach (($db['notices'] ?? []) as $n) {
            if (isset($n['id']) && intval($n['id']) > $max_notice_id) {
                $max_notice_id = intval($n['id']);
            }
        }
        $db['notices'][] = [
            'id' => $max_notice_id + 1,
            'title' => $title,
            'desc' => $desc,
            'author' => $user['name'],
            'role' => 'Faculty (' . $user['dept'] . ')',
            'target_audience' => $user['dept'],
            'date' => date('d M Y'),
            'expiry' => $expiry,
            'attachment' => $file_name,
            'size' => $file_name ? '1.5MB' : ''
        ];
        $db['recent_activity'] = array_merge([
            [
                'type' => 'notice',
                'title' => 'New Notice: ' . $title,
                'desc' => 'Published by ' . $user['name'],
                'time' => 'Just now'
            ]
        ], array_slice($db['recent_activity'] ?? [], 0, 9));
        save_db($db);
        $_SESSION['success_message'] = "Notice published successfully.";
    } else {
        $_SESSION['error_message'] = "Title and Description are required.";
    }
    header("Location: faculty_dashboard.php");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_assignment') {
    $sub_id = intval($_POST['assignment_id']);
    $marks = trim($_POST['marks'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $status = trim($_POST['status'] ?? 'Graded');
    
    $updated = false;
    if (isset($db['assignment_submissions'])) {
        foreach ($db['assignment_submissions'] as &$sub) {
            if ($sub['id'] === $sub_id) {
                if (strpos($marks, '/') === false && is_numeric($marks)) {
                    $marks = $marks . ' / 10';
                }
                $sub['marks'] = $marks;
                $sub['status'] = $status;
                $sub['remarks'] = $remarks;
                $sub['evaluated_at'] = date('d M Y h:i A');
                $updated = true;
                break;
            }
        }
    }
    if ($updated) {
        save_db($db);
        $_SESSION['success_message'] = "Assignment submission status updated to '{$status}' successfully.";
    }
    header("Location: faculty_dashboard.php");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_assignment') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    
    $title = trim($_POST['title'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $unit_number = intval($_POST['unit_number'] ?? 1);
    $due_date = trim($_POST['due_date'] ?? '');
    $target_dept = trim($_POST['target_dept'] ?? '');
    $target_year = intval($_POST['target_year'] ?? 1);
    $target_sem_num = intval($_POST['target_sem'] ?? 1);
    $sem_num = (($target_year - 1) * 2) + $target_sem_num;
    $sem_suffixes = [1 => 'st', 2 => 'nd', 3 => 'rd'];
    $suffix = $sem_suffixes[$sem_num] ?? 'th';
    $target_sem = $sem_num . $suffix . ' Semester';
    $target_div = trim($_POST['target_div'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file_name = '';

    if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['assignment_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
            $raw_file_name = 'sa_' . uniqid() . '_' . time() . '.' . $ext;
            if (!is_dir(__DIR__ . '/uploads/assignments')) { mkdir(__DIR__ . '/uploads/assignments', 0777, true); }
            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], __DIR__ . '/uploads/assignments/' . $raw_file_name)) {
                $file_name = 'assignments/' . $raw_file_name;
            }
        }
    }

    if (!empty($title) && !empty($file_name)) {
        $formatted_due = $due_date ? date('d M Y', strtotime($due_date)) . ' 11:59 PM' : 'No Due Date';
        $pub_date = date('d M Y h:i A');
        
        $db = get_db();
        
        // Ensure unit assignment (unit) exists in assignments table
        $unit_id = 0;
        foreach ($db['assignments'] as $a) {
            if ($a['unit'] === $unit_number) {
                $unit_id = $a['id'];
                break;
            }
        }
        
        if ($unit_id === 0) {
            $max_unit_id = 0;
            foreach (($db['assignments'] ?? []) as $a) {
                if (isset($a['id']) && intval($a['id']) > $max_unit_id) {
                    $max_unit_id = intval($a['id']);
                }
            }
            $unit_id = $max_unit_id + 1;
            $db['assignments'][] = [
                'id' => $unit_id,
                'unit' => $unit_number,
                'title' => 'Unit ' . $unit_number,
                'desc' => 'Unit ' . $unit_number . ' Subject Assignments'
            ];
        }

        $max_sa_id = 0;
        foreach (($db['subject_assignments'] ?? []) as $sa) {
            if (isset($sa['id']) && intval($sa['id']) > $max_sa_id) {
                $max_sa_id = intval($sa['id']);
            }
        }
        $new_sa_id = $max_sa_id + 1;
        $db['subject_assignments'][] = [
            'id' => $new_sa_id,
            'assignment_id' => $unit_id,
            'subject_name' => $subject_name,
            'assignment_title' => $title,
            'question_pdf' => $file_name,
            'due' => $formatted_due,
            'created_by' => $user['name'],
            'department' => $target_dept,
            'division' => $target_div,
            'semester' => $target_sem,
            'description' => $description,
            'published_date' => $pub_date
        ];
        
        $db['recent_activity'] = array_merge([
            [
                'type' => 'assignment',
                'title' => 'New Assignment: ' . $title,
                'desc' => 'Subject: ' . $subject_name . ' | Due: ' . $formatted_due,
                'time' => 'Just now'
            ]
        ], array_slice($db['recent_activity'] ?? [], 0, 9));
        
        save_db($db);
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Assignment published successfully targeting Department: {$target_dept}, Div: {$target_div}, Sem: {$target_sem}."]);
            exit;
        }
        $_SESSION['success_message'] = "Assignment published successfully targeting Department: {$target_dept}, Div: {$target_div}, Sem: {$target_sem}.";
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Assignment Title and a valid file (PDF, Word, or Image) are required."]);
            exit;
        }
        $_SESSION['error_message'] = "Assignment Title and a valid file (PDF, Word, or Image) are required.";
    }
    header("Location: faculty_dashboard.php?tab=assignments");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_grievance') {
    $g_id = intval($_POST['grievance_id']);
    $updated = false;
    foreach ($db['grievances'] as &$g) {
        if ($g['id'] === $g_id) {
            $g['status'] = 'Resolved';
            $updated = true;
            break;
        }
    }
    if ($updated) {
        save_db($db);
        $_SESSION['success_message'] = "Grievance marked as resolved.";
    }
    header("Location: faculty_dashboard.php");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_assignment_grievance') {
    $g_id = intval($_POST['grievance_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'Resolved');
    $reply = trim($_POST['reply'] ?? '');
    
    $updated = false;
    if (isset($db['assignment_grievances'])) {
        foreach ($db['assignment_grievances'] as &$g) {
            if ($g['id'] === $g_id) {
                $g['status'] = $status;
                $g['reply'] = $reply;
                $updated = true;

                // Sync status and response to main grievances table as well
                if (isset($db['grievances'])) {
                    foreach ($db['grievances'] as &$mg) {
                        if ((isset($mg['assignment_grievance_id']) && $mg['assignment_grievance_id'] === $g_id) || 
                            (isset($mg['subject_assignment_id']) && $mg['subject_assignment_id'] == $g['subject_assignment_id'] && $mg['student_id'] === $g['student_id'])) {
                            $mg['status'] = $status;
                            if (!empty($reply)) {
                                $mg['replies'][] = [
                                    'author' => $user['name'] ?? 'Faculty',
                                    'role' => 'Faculty',
                                    'date' => date('d M Y h:i A'),
                                    'message' => $reply
                                ];
                            }
                        }
                    }
                }

                // Notify affected student
                $db['recent_activity'] = array_merge([
                    [
                        'title' => 'Grievance Status Change',
                        'desc' => "Your grievance regarding assignment has been updated to {$status}.",
                        'time' => 'Just now'
                    ]
                ], array_slice($db['recent_activity'] ?? [], 0, 4));
                break;
            }
        }
    }
    if ($updated) {
        save_db($db);
        $_SESSION['success_message'] = "Grievance status updated to {$status}. Notification sent to student.";
    } else {
        $_SESSION['error_message'] = "Grievance ID not found.";
    }
    header("Location: faculty_dashboard.php?tab=grievances");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'replace_question_pdf') {
    $sa_id = intval($_POST['subject_assignment_id'] ?? 0);
    $file_uploaded = false;
    
    if (isset($_FILES['new_question_pdf']) && $_FILES['new_question_pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['new_question_pdf']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'])) {
            $dest_filename = 'sa_' . $sa_id . '_' . time() . '.' . $ext;
            if (!is_dir(__DIR__ . '/uploads')) {
                mkdir(__DIR__ . '/uploads', 0777, true);
            }
            if (move_uploaded_file($_FILES['new_question_pdf']['tmp_name'], __DIR__ . '/uploads/' . $dest_filename)) {
                if (isset($db['subject_assignments'])) {
                    foreach ($db['subject_assignments'] as &$sa) {
                        if ($sa['id'] === $sa_id) {
                            $sa['question_pdf'] = $dest_filename;
                            $file_uploaded = true;

                            // Notify students automatically
                            $db['recent_activity'] = array_merge([
                                [
                                    'title' => 'Assignment PDF Replaced',
                                    'desc' => "Question file replaced for {$sa['subject_name']}.",
                                    'time' => 'Just now'
                                ]
                            ], array_slice($db['recent_activity'] ?? [], 0, 4));
                            break;
                        }
                    }
                }
            }
        }
    }
    
    if ($file_uploaded) {
        save_db($db);
        $_SESSION['success_message'] = "Question file replaced successfully and students notified.";
    } else {
        $_SESSION['error_message'] = "Failed to replace file (only PDF, DOC, DOCX, JPG, JPEG, PNG, GIF formats accepted).";
    }
    header("Location: faculty_dashboard.php?tab=grievances");
    exit;
}

// Reload database to get fresh updates
$db = get_db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College ERP Portal - Faculty Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="theme-faculty">
    <div class="dashboard-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="sidebar-brand">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <div>
                        <span>College ERP</span>
                        <span class="sub">Faculty Portal</span>
                    </div>
                </div>
                    <li><a class="sidebar-nav-item" onclick="switchTab('profile', this)"><i class="fa-solid fa-id-card"></i><span>My Profile</span></a></li>
                    <li><a class="sidebar-nav-item active" onclick="switchTab('dashboard', this)"><i class="fa-solid fa-border-all"></i><span>Dashboard</span></a></li>

                    <li><a class="sidebar-nav-item" onclick="switchTab('leaves', this)"><i class="fa-solid fa-envelope-open-text"></i><span>Leave Approvals</span></a></li>
                    <li><a class="sidebar-nav-item" onclick="switchTab('assignments', this)"><i class="fa-solid fa-file-invoice"></i><span>Manage Assignments</span></a></li>
                    <li><a class="sidebar-nav-item" onclick="switchTab('notices', this)"><i class="fa-solid fa-bullhorn"></i><span>Publish Notices</span></a></li>
                    <li><a class="sidebar-nav-item" onclick="switchTab('grievances', this)"><i class="fa-solid fa-circle-exclamation"></i><span>Grievance</span></a></li>
                </ul>
            </div>
            <div class="sidebar-footer">
                <a href="logout.php" class="sidebar-nav-item" style="background: rgba(239, 68, 68, 0.1); color: #f87171;"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
            </div>
        </aside>

        <!-- Main Dashboard View Area -->
        <main class="main-content">
            <!-- Header Widget -->
            <header class="dashboard-header">
                <div class="page-title-box">
                    <h2 id="currentTabTitle">Dashboard</h2>
                    <p id="currentTabSubtitle">Quick access to all essential faculty services.</p>
                </div>
                <div class="user-profile-widget">
                    <button class="theme-toggle-btn" title="Toggle Dark/Light Theme" onclick="toggleDarkMode()">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                    <div class="notification-wrapper" style="position: relative;">
                        <div class="notification-bell" id="notificationToggle" style="cursor:pointer;">
                            <i class="fa-regular fa-bell"></i>
                            <?php if (!empty($db['recent_activity'])): ?>
                            <span class="badge" style="position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; border-radius: 50%; width: 16px; height: 16px; font-size: 0.6rem; display: flex; align-items: center; justify-content: center; font-weight: bold;"><?php echo min(count($db['recent_activity']), 9); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-dropdown" id="notificationDropdown" style="display: none; position: absolute; top: 120%; right: 0; width: 320px; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: 1px solid var(--border-color); z-index: 100; overflow: hidden; cursor: default;">
                            <div style="padding: 1rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-page);">
                                <h4 style="margin: 0; font-size: 1rem; color: var(--text-primary);">Notifications</h4>
                                <span style="font-size: 0.75rem; color: var(--primary-color); cursor: pointer; font-weight: 600;" onclick="fetch(window.location.href, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=clear_notifications'}).then(() => { this.parentElement.nextElementSibling.innerHTML='<div style=\'padding: 2rem 1rem; text-align: center; color: var(--text-secondary); font-size: 0.9rem;\'><i class=\'fa-regular fa-bell-slash\' style=\'font-size: 1.5rem; margin-bottom: 0.5rem; color: #cbd5e1;\'></i><br>No new notifications</div>'; let b = document.querySelector('#notificationToggle .badge'); if(b) b.style.display='none'; });">Mark all as read</span>
                            </div>
                            <div style="max-height: 350px; overflow-y: auto; text-align: left;">
                                <?php if (empty($db['recent_activity'])): ?>
                                    <div style="padding: 2rem 1rem; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                                        <i class="fa-regular fa-bell-slash" style="font-size: 1.5rem; margin-bottom: 0.5rem; color: #cbd5e1;"></i><br>
                                        No new notifications
                                    </div>
                                <?php else: ?>
                                    <?php foreach(array_slice($db['recent_activity'], 0, 5) as $idx => $activity): ?>
                                    <?php
                                    $targetTab = 'dashboard';
                                    $t = strtolower($activity['title'] ?? '');
                                    if (strpos($t, 'leave') !== false) $targetTab = 'leaves';
                                    elseif (strpos($t, 'grievance') !== false) $targetTab = 'grievance';
                                    elseif (strpos($t, 'assignment') !== false) $targetTab = 'assignments';
                                    elseif (strpos($t, 'notice') !== false) $targetTab = 'notices';
                                    ?>
                                    <div onclick="triggerTab('<?php echo $targetTab; ?>')" style="padding: 1rem; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s; <?php echo $idx === 0 ? 'background: #f0f9ff;' : ''; ?>" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='<?php echo $idx === 0 ? '#f0f9ff' : 'transparent'; ?>'">
                                        <div style="display: flex; gap: 0.75rem;">
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--bg-alt); color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fa-solid fa-bolt"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; font-size: 0.9rem; color: var(--text-primary); margin-bottom: 0.15rem;"><?php echo htmlspecialchars($activity['title'] ?? 'Notification'); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.25rem;"><?php echo htmlspecialchars($activity['desc'] ?? ''); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--text-muted);"><i class="fa-regular fa-clock" style="margin-right: 3px;"></i> <?php echo htmlspecialchars($activity['time'] ?? 'Just now'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                        </div>
                        <script>
                            function triggerTab(tabName) {
                                if (!tabName) return;
                                if (tabName === 'grievance') {
                                    let hasGrievances = false;
                                    document.querySelectorAll('.sidebar-nav-item').forEach(el => {
                                        if ((el.getAttribute('onclick')||'').includes("'grievances'") || el.getAttribute('data-tab') === 'grievances') hasGrievances = true;
                                    });
                                    if (hasGrievances) tabName = 'grievances';
                                }
                                if (tabName === 'grievances') {
                                    let hasGrievance = false;
                                    document.querySelectorAll('.sidebar-nav-item').forEach(el => {
                                        if ((el.getAttribute('onclick')||'').includes("'grievance'") && !(el.getAttribute('onclick')||'').includes("'grievances'")) hasGrievance = true;
                                        if (el.getAttribute('data-tab') === 'grievance') hasGrievance = true;
                                    });
                                    if (hasGrievance) tabName = 'grievance';
                                }
                                
                                document.getElementById('notificationDropdown').style.display = 'none';
                                
                                let items = document.querySelectorAll('.sidebar-nav-item');
                                let targetEl = null;
                                for (let i=0; i<items.length; i++) {
                                    let onclick = items[i].getAttribute('onclick') || '';
                                    let dataTab = items[i].getAttribute('data-tab') || '';
                                    if (onclick.includes("'" + tabName + "'") || dataTab === tabName) {
                                        targetEl = items[i];
                                        break;
                                    }
                                }
                                
                                if (typeof switchTab === 'function') {
                                    if (targetEl && switchTab.length === 2) {
                                        switchTab(tabName, targetEl);
                                    } else {
                                        try { switchTab(tabName); } catch(e) {}
                                    }
                                }
                            }

                            document.getElementById('notificationToggle').addEventListener('click', function(e) {
                                e.stopPropagation();
                                var dropdown = document.getElementById('notificationDropdown');
                                dropdown.style.display = dropdown.style.display === 'none' || dropdown.style.display === '' ? 'block' : 'none';
                            });
                            document.addEventListener('click', function(e) {
                                var dropdown = document.getElementById('notificationDropdown');
                                var toggle = document.getElementById('notificationToggle');
                                if (dropdown && !dropdown.contains(e.target) && !toggle.contains(e.target)) {
                                    dropdown.style.display = 'none';
                                }
                            });
                        </script>
                    </div>
                    <div class="user-avatar-box">
                        <?= get_initials_avatar($user['name'], 40, 16, 2) ?>
                        <div class="user-details">
                            <span class="name"><?php echo htmlspecialchars($user['name']); ?></span>
                            <span class="role"><?php echo htmlspecialchars($user['dept']); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Success/Error alert banner -->
            <?php if (!empty($success_message)): ?>
                <div class="toast-notification toast-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="toast-notification toast-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- 0. DASHBOARD PAGE                            -->
            <!-- ============================================ -->
            <div id="tab-dashboard" class="app-view active">
                <h3 style="margin-bottom: 1.5rem; color: var(--text-primary);">Portal Summary</h3>
                <?php
                // Calculate summaries
                // Leaves: filter by faculty's students
                $pending_leaves = 0;
                $total_leaves = 0;
                foreach ($db['leaves'] ?? [] as $l) {
                    $is_ours = false;
                    foreach ($db['students'] as $stu) {
                        if ($stu['name'] === $l['applicant_name']) {
                            $student_div = $stu['division'] ?? '';
                            if (in_array($student_div, $faculty_divisions)) {
                                $is_ours = true;
                            }
                            break;
                        }
                    }
                    if ($is_ours) {
                        $total_leaves++;
                        if (($l['status'] ?? '') === 'Pending') $pending_leaves++;
                    }
                }

                // Assignments: filter by subject
                $ungraded_submissions = 0;
                $total_submissions = 0;
                foreach ($db['assignment_submissions'] ?? [] as $sub) {
                    if (in_array($sub['subject_id'], $faculty_subjects)) {
                        $total_submissions++;
                        if (($sub['status'] ?? '') === 'submitted' || strtolower($sub['marks'] ?? '') === 'pending') {
                            $ungraded_submissions++;
                        }
                    }
                }
                
                // Grievances: filter by division (general) or subject (assignment)
                $active_grievances = 0;
                foreach ($db['grievances'] ?? [] as $g) {
                    $is_ours = false;
                    foreach ($db['students'] as $stu) {
                        if ($stu['id'] === $g['student_id']) {
                            $student_div = $stu['division'] ?? '';
                            if (in_array($student_div, $faculty_divisions)) {
                                $is_ours = true;
                            }
                            break;
                        }
                    }
                    if ($is_ours && ($g['status'] ?? '') !== 'Resolved') {
                        $active_grievances++;
                    }
                }
                foreach ($db['assignment_grievances'] ?? [] as $ag) {
                    $sa_item = null;
                    foreach ($db['subject_assignments'] as $sa) {
                        if ($sa['id'] == $ag['subject_assignment_id']) {
                            $sa_item = $sa;
                            break;
                        }
                    }
                    if ($sa_item && in_array($sa_item['subject_name'], $faculty_subjects)) {
                        if (($ag['status'] ?? '') !== 'Resolved') {
                            $active_grievances++;
                        }
                    }
                }
                
                // Notices: only count notices created by the logged-in faculty
                $total_notices = 0;
                foreach ($db['notices'] ?? [] as $n) {
                    if ($n['author'] === $current_faculty['name']) {
                        $total_notices++;
                    }
                }
                ?>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 2rem;">
                    
                    <!-- Leave Approvals Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 2rem 1.5rem; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column; align-items: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';" onclick="switchTab('leaves', document.querySelectorAll('.sidebar-nav-item')[2])">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #dcfce7; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem;">
                            <i class="fa-solid fa-envelope-open-text"></i>
                        </div>
                        <h4 style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Leaves Pending</h4>
                        <div style="color: #10b981; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem;"><?= $pending_leaves ?></div>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">Out of <?= $total_leaves ?> total leaves</p>
                    </div>

                    <!-- Manage Assignments Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 2rem 1.5rem; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column; align-items: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';" onclick="switchTab('assignments', document.querySelectorAll('.sidebar-nav-item')[3])">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #f3e8ff; color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem;">
                            <i class="fa-solid fa-file-invoice"></i>
                        </div>
                        <h4 style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Ungraded Work</h4>
                        <div style="color: #6366f1; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem;"><?= $ungraded_submissions ?></div>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">Out of <?= $total_submissions ?> submissions</p>
                    </div>

                    <!-- Publish Notices Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 2rem 1.5rem; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column; align-items: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';" onclick="switchTab('notices', document.querySelectorAll('.sidebar-nav-item')[4])">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #dbeafe; color: #3b82f6; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <h4 style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Notices</h4>
                        <div style="color: #3b82f6; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem;"><?= $total_notices ?></div>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">Recent updates</p>
                    </div>

                    <!-- Grievance Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 2rem 1.5rem; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column; align-items: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';" onclick="switchTab('grievances', document.querySelectorAll('.sidebar-nav-item')[5])">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #ffedd5; color: #f97316; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                        </div>
                        <h4 style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Grievances</h4>
                        <div style="color: #f97316; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem;"><?= $active_grievances ?></div>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0;">Requires resolution</p>
                    </div>

                </div>
            </div>

            <!-- ============================================ -->
            <!-- -1. PROFILE PAGE                             -->
            <!-- ============================================ -->
            <div id="tab-profile" class="app-view">
                <div class="settings-form-container" style="max-width: 800px; margin: 0 auto; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 2rem; box-shadow: var(--box-shadow-subtle);">
                    <div style="display: flex; gap: 2rem; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 2rem; margin-bottom: 2rem;">
                        <?= get_initials_avatar($user['name'], 120, 48, 4) ?>
                        <div>
                            <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin: 0 0 0.5rem 0;"><?= htmlspecialchars($user['name']) ?></h2>
                            <span class="status-pill graded" style="font-size: 0.85rem; padding: 0.25rem 0.75rem; background: #dcfce7; color: #15803d;">Active Faculty</span>
                            <p style="margin: 0.5rem 0 0 0; color: var(--text-muted); font-size: 0.95rem;">ID: <?= htmlspecialchars($user['username']) ?> | <?= htmlspecialchars($user['dept']) ?></p>
                        </div>
                    </div>
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Full Name</label>
                            <input type="text" readonly value="<?= htmlspecialchars($user['name']) ?>" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Employee ID</label>
                            <input type="text" readonly value="<?= htmlspecialchars($user['username']) ?>" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                    </div>
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Email Address</label>
                            <input type="text" readonly value="<?= htmlspecialchars($current_faculty['email'] ?? '') ?>" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Phone Number</label>
                            <input type="text" readonly value="<?= htmlspecialchars($current_faculty['phone'] ?? '') ?>" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem;">
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Department</label>
                            <input type="text" readonly value="<?= htmlspecialchars($user['dept']) ?>" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                        <div class="form-group-col">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Designation</label>
                            <input type="text" readonly value="Associate Professor" style="width: 100%; background: var(--bg-alt); cursor: not-allowed; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm);">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 1. LEAVE APPROVALS TAB                       -->
            <!-- ============================================ -->
            <div id="tab-leaves" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters" style="justify-content: flex-start; background: var(--bg-alt); border-bottom: 1px solid var(--border-color);">
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); padding: 0.5rem 0.25rem;">Active Leave Requests</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Student Details</th>
                                <th>Reason</th>
                                <th>From Date</th>
                                <th>To Date</th>
                                <th>Leave Form</th>
                                <th style="text-align: center;">Status</th>
                                <th style="text-align: center; width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($db['leaves'] as $leave): 
                                $is_ours = false;
                                foreach ($db['students'] as $stu) {
                                    if ($stu['name'] === $leave['applicant_name']) {
                                        if (in_array($stu['division'] ?? '', $faculty_divisions)) {
                                            $is_ours = true;
                                        }
                                        break;
                                    }
                                }
                                if (!$is_ours) continue;
                            ?>
                                <tr>
                                    <td><?php echo $leave['id']; ?></td>
                                    <td>
                                        <div class="publisher-cell">
                                            <span class="pub-name"><?php echo htmlspecialchars($leave['applicant_name'] ?? 'Prasad Kulkarni'); ?></span>
                                            <span class="pub-role"><?php echo htmlspecialchars($leave['applicant_role'] ?? 'Student'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600;"><?php echo htmlspecialchars($leave['reason']); ?></span>
                                    </td>
                                    <td>
                                        <span class="date-cell"><?php echo htmlspecialchars($leave['from']); ?></span>
                                    </td>
                                    <td>
                                        <span class="date-cell"><?php echo htmlspecialchars($leave['to']); ?></span>
                                    </td>
                                    <td>
                                        <div class="publisher-cell" style="flex-direction:row; align-items:center; gap:0.5rem;">
                                            <?php 
                                                $ext = pathinfo($leave['file'], PATHINFO_EXTENSION);
                                                $is_pdf = (strtolower($ext) === 'pdf');
                                            ?>
                                            <i class="fa-solid <?php echo $is_pdf?'fa-file-pdf':'fa-file-word'; ?>" style="font-size:1.15rem; color:<?php echo $is_pdf?'#ef4444':'#0284c7'; ?>"></i>
                                            <a href="<?php echo htmlspecialchars($leave['file']); ?>" target="_blank" class="pub-name" style="font-size:0.9rem; font-weight:500; text-decoration:none; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($leave['file']); ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php 
                                            $status = strtolower($leave['status']);
                                            $pill_class = ($status === 'approved') ? 'graded' : (($status === 'pending') ? 'pending' : 'rejected');
                                        ?>
                                        <span class="status-pill <?php echo $pill_class; ?>"><?php echo htmlspecialchars($leave['status']); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($status === 'pending'): ?>
                                            <div class="faculty-actions-cell" style="display:flex; gap:0.5rem; justify-content:center;">
                                                <form method="POST" action="delete.php" style="margin:0;">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="type" value="leaves">
                                                    <input type="hidden" name="id" value="<?php echo $leave['id']; ?>">
                                                    <button type="submit" class="btn-reject" style="padding: 0.4rem 0.6rem; border-radius:4px;" title="Delete" onclick="return confirm('Delete this leave request?');"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                                <form method="POST" action="faculty_dashboard.php" style="margin:0;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                                    <button type="submit" class="btn-approve">
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="POST" action="faculty_dashboard.php" style="margin:0;">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                                    <button type="submit" class="btn-reject">
                                                        <i class="fa-solid fa-xmark"></i> Reject
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">No Action Needed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- ASSIGNMENTS TAB                              -->
            <!-- ============================================ -->
            <div id="tab-assignments" class="app-view">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h2 style="font-size: 2.25rem; color: #4f46e5; font-weight: 800; margin-bottom: 0.5rem;">Manage Subject Assignments</h2>
                    <p style="color: var(--text-muted);">Create new subject assignments, specify target classes, and grade student submissions.</p>
                </div>

                <!-- Publish Assignment Form -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 3rem; box-shadow: var(--box-shadow-subtle);">
                    <form id="publishAssignmentForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="publish_assignment">
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 2rem; margin-bottom: 1.5rem;">
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Assignment Title</label>
                                <input type="text" name="title" required placeholder="e.g. Introduction to Basics" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Due Date</label>
                                <input type="date" name="due_date" required min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Target Year</label>
                                <select name="target_year" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <option value="1">First Year (1)</option>
                                    <option value="2">Second Year (2)</option>
                                    <option value="3" selected>Third Year (3)</option>
                                    <option value="4">Fourth Year (4)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Target Semester</label>
                                <select name="target_sem" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <option value="1" selected>Semester 1</option>
                                    <option value="2">Semester 2</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Target Division</label>
                                <select name="target_div" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <option value="A">Division A</option>
                                    <option value="B" selected>Division B</option>
                                    <option value="C">Division C</option>
                                    <option value="D">Division D</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Subject</label>
                                 <select name="subject_name" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <?php foreach ($faculty_subjects as $fs): ?>
                                        <option value="<?php echo htmlspecialchars($fs); ?>"><?php echo htmlspecialchars($fs); ?></option>
                                    <?php endforeach; ?>
                                 </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Unit Number</label>
                                <select name="unit_number" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <option value="1">Unit 1</option>
                                    <option value="2">Unit 2</option>
                                    <option value="3">Unit 3</option>
                                    <option value="4">Unit 4</option>
                                    <option value="5">Unit 5</option>
                                    <option value="6">Unit 6</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Target Department</label>
                                <select name="target_dept" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; background: var(--bg-card);">
                                    <option value="Information Technology" selected>Information Technology</option>
                                    <option value="Computer Engineering">Computer Engineering</option>
                                    <option value="Mechanical Engineering">Mechanical Engineering</option>
                                    <option value="Civil Engineering">Civil Engineering</option>
                                </select>
                            </div>
                            <div style="grid-column: span 4;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Assignment Description</label>
                                <textarea name="description" required rows="3" placeholder="Provide detailed instructions..." style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; resize: vertical;"></textarea>
                            </div>
                        </div>
                        
                        <!-- Dynamic Drop Area -->
                        <div id="facultyDropZone" style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 2rem; background: var(--bg-page); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; cursor: pointer; transition: all 0.2s;">
                            <div style="display: flex; align-items: center; gap: 2rem; pointer-events: none;">
                                <div id="upload-icon-container" style="width: 56px; height: 56px; background: #e0e7ff; color: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                </div>
                                <div style="text-align: left;">
                                    <h4 id="upload-title-text" style="font-weight: 600; margin-bottom: 0.25rem; font-size: 1.05rem; color: var(--text-primary);">Upload Question File *</h4>
                                    <p id="upload-status-text" style="font-size: 0.9rem; color: var(--text-muted);">Click here to <span style="color: #4f46e5; font-weight: 600;">browse</span> and select a file</p>
                                    <input id="file-upload" type="file" name="assignment_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display: none;">
                                    <p id="upload-allowed-text" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">PDF, DOC, DOCX, JPG, JPEG, PNG allowed (Max 10MB)</p>
                                </div>
                            </div>
                            <div id="dynamic-btn-area">
                                <label for="file-upload" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.65rem 1.25rem; border-radius: 6px; color: #4f46e5; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background 0.2s;">
                                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Choose File
                                </label>
                            </div>
                        </div>

                        <!-- Progress Bar Container -->
                        <div id="uploadProgressContainer" style="display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 0.25rem;">
                                <span id="progressStatusLabel">Uploading...</span>
                                <span id="progressPercentText">0%</span>
                            </div>
                            <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                <div id="progressBarFill" style="width: 0%; height: 100%; background: #10b981; transition: width 0.1s;"></div>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end;">
                            <button id="btnPublishAssignment" type="submit" style="background: #10b981; color: white; border: none; padding: 0.85rem 1.75rem; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 1rem; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2); transition: transform 0.2s, box-shadow 0.2s;">Publish Assignment</button>
                        </div>
                    </form>
                </div>

                <h3 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary);">Your Assignments</h3>

                <!-- Interactive Filters -->
                <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
                    <!-- Year Tabs -->
                    <div style="display: flex; gap: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem;">
                        <button type="button" class="filter-year-btn" data-year="1" style="background: none; border: none; font-size: 1rem; font-weight: 700; color: #4f46e5; padding-bottom: 0.5rem; border-bottom: 2px solid #4f46e5; cursor: pointer; margin-bottom: -10px; font-family: inherit;">Year 1</button>
                        <button type="button" class="filter-year-btn" data-year="2" style="background: none; border: none; font-size: 1rem; font-weight: 600; color: var(--text-secondary); padding-bottom: 0.5rem; cursor: pointer; margin-bottom: -10px; font-family: inherit;">Year 2</button>
                        <button type="button" class="filter-year-btn" data-year="3" style="background: none; border: none; font-size: 1rem; font-weight: 600; color: var(--text-secondary); padding-bottom: 0.5rem; cursor: pointer; margin-bottom: -10px; font-family: inherit;">Year 3</button>
                        <button type="button" class="filter-year-btn" data-year="4" style="background: none; border: none; font-size: 1rem; font-weight: 600; color: var(--text-secondary); padding-bottom: 0.5rem; cursor: pointer; margin-bottom: -10px; font-family: inherit;">Year 4</button>
                    </div>

                    <!-- Semester Pills -->
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="button" class="filter-sem-btn" data-sem="1" style="background: #f5f3ff; color: #8b5cf6; border: 1px solid #c084fc; padding: 0.4rem 1rem; border-radius: 6px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Semester 1</button>
                        <button type="button" class="filter-sem-btn" data-sem="2" style="background: var(--bg-card); color: var(--text-secondary); border: 1px solid var(--border-color); padding: 0.4rem 1rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Semester 2</button>
                    </div>

                    <!-- Division Buttons -->
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="filter-div-btn" data-div="A" style="background: #3b82f6; color: white; border: none; padding: 0.4rem 1rem; border-radius: 6px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Div A</button>
                        <button type="button" class="filter-div-btn" data-div="B" style="background: var(--bg-alt); color: var(--text-secondary); border: none; padding: 0.4rem 1rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Div B</button>
                        <button type="button" class="filter-div-btn" data-div="C" style="background: var(--bg-alt); color: var(--text-secondary); border: none; padding: 0.4rem 1rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Div C</button>
                        <button type="button" class="filter-div-btn" data-div="D" style="background: var(--bg-alt); color: var(--text-secondary); border: none; padding: 0.4rem 1rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: inherit;">Div D</button>
                    </div>
                </div>

                <?php 
                $my_published_sas = [];
                if (isset($db['subject_assignments'])) {
                    foreach ($db['subject_assignments'] as $sa) {
                        if (in_array($sa['subject_name'], $faculty_subjects)) {
                            $my_published_sas[] = $sa;
                        }
                    }
                }

                if (empty($my_published_sas)):
                ?>
                    <div style="padding: 3rem; text-align: center; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color); color: var(--text-muted);">
                        No assignments published by you yet.
                    </div>
                <?php 
                else:
                    foreach ($my_published_sas as $sa): 
                        // Fetch unit info
                        $unit_no = 'N/A';
                        foreach ($db['assignments'] as $a) {
                            if ($a['id'] == $sa['assignment_id']) {
                                $unit_no = $a['unit'];
                                break;
                            }
                        }
                        // Fetch submissions for this subject assignment
                        $submissions = [];
                        if (isset($db['assignment_submissions'])) {
                            foreach ($db['assignment_submissions'] as $sub) {
                                if ($sub['subject_assignment_id'] == $sa['id']) {
                                    $submissions[] = $sub;
                                }
                            }
                        }
                        $has_submissions = (count($submissions) > 0);

                        // Parse semester to Year & Semester
                        $sem_str = $sa['semester'] ?? '';
                        $sem_num = 1;
                        if (preg_match('/(\d+)/', $sem_str, $matches)) {
                            $sem_num = intval($matches[1]);
                        } elseif (stripos($sem_str, 'Semester 1') !== false) {
                            $sem_num = 1;
                        } elseif (stripos($sem_str, 'Semester 2') !== false) {
                            $sem_num = 2;
                        }
                        $year_num = ceil($sem_num / 2);
                        if ($year_num < 1) $year_num = 1;
                        if ($year_num > 4) $year_num = 4;
                        $sem_in_year = ($sem_num % 2 === 0) ? 2 : 1;

                        // Calculate assigned, submitted, pending counts
                        $assigned_count = 0;
                        if (isset($db['students'])) {
                            foreach ($db['students'] as $student) {
                                $st_info = parse_student_dept_info($student['dept'] ?? $student['department'] ?? '');
                                $st_dept = $st_info['department'];
                                $st_div = $st_info['division'];
                                $st_sem = $student['semester'] ?? '';

                                if (match_department($sa['department'] ?? '', $st_dept) &&
                                    match_division($sa['division'] ?? '', $st_div) &&
                                    match_semester($sa['semester'] ?? '', $st_sem)) {
                                    $assigned_count++;
                                }
                            }
                        }
                        $submitted_count = count($submissions);
                        $pending_count = max(0, $assigned_count - $submitted_count);
                ?>
                <div class="assignment-card-item" data-year="<?= $year_num ?>" data-semester="<?= $sem_in_year ?>" data-division="<?= htmlspecialchars($sa['division'] ?? 'A') ?>" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 1.5rem; box-shadow: var(--box-shadow-subtle); overflow: hidden;">
                    <div style="padding: 1.5rem; display: flex; gap: 2rem; align-items: flex-start; border-bottom: <?php echo $has_submissions ? '1px solid var(--border-color)' : 'none'; ?>;">
                        <div style="width: 48px; height: 48px; background: #f5f3ff; color: #8b5cf6; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0;">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div>
                                    <h4 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--text-primary);">Unit <?= htmlspecialchars($unit_no) ?> - <?= htmlspecialchars($sa['assignment_title']) ?></h4>
                                    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0.65rem;"><?= htmlspecialchars($sa['description'] ?? 'Solve all questions.') ?></p>
                                    
                                    <!-- Badges -->
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                                        <span style="background: var(--bg-alt); color: #0369a1; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Target: Year <?= $year_num ?>, Sem <?= $sem_in_year ?> - Div <?= htmlspecialchars($sa['division'] ?? 'A') ?></span>
                                        <span style="background: var(--bg-alt); color: var(--text-secondary); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Assigned: <?= $assigned_count ?></span>
                                        <span style="background: #dcfce7; color: #166534; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Submitted: <?= $submitted_count ?></span>
                                        <span style="background: #fee2e2; color: #991b1b; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Pending: <?= $pending_count ?></span>
                                    </div>
                                </div>
                                <form method="POST" action="delete.php" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="type" value="subject_assignments">
                                    <input type="hidden" name="id" value="<?= $sa['id'] ?>">
                                    <button type="submit" style="background:transparent;border:none;color:#ef4444;cursor:pointer;padding:0.4rem; font-size:1rem;" title="Delete Assignment" onclick="return confirm('Delete this assignment?');"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                            <div style="display: flex; align-items: center; gap: 2rem; font-size: 0.85rem; color: #4f46e5; font-weight: 500;">
                                <span><i class="fa-regular fa-calendar"></i> Due: <?= htmlspecialchars($sa['due'] ?? $sa['due_date'] ?? '') ?></span>
                                <?php if (!empty($sa['question_pdf'])): ?>
                                    <a href="uploads/<?= htmlspecialchars($sa['question_pdf']) ?>" target="_blank" style="color: #0284c7; text-decoration: none;"><i class="fa-solid fa-paperclip"></i> @<?= basename($sa['question_pdf']) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($has_submissions): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 700px;">
                            <thead style="background: var(--bg-page); font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em;">
                                <tr>
                                    <th style="padding: 1rem 1.5rem; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color); width: 60px;">#</th>
                                    <th style="padding: 1rem 1.5rem; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color); width: 140px;">STUDENT ID</th>
                                    <th style="padding: 1rem 1.5rem; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color);">STUDENT NAME</th>
                                    <th style="padding: 1rem 1.5rem; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color);">SUBMITTED FILE</th>
                                    <th style="padding: 1rem 1.5rem; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color); width: 420px;">MARKS (OUT OF 10)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $i => $sub): ?>
                                <tr style="border-bottom: 1px solid var(--border-color); background: var(--bg-card); vertical-align: top;">
                                    <td style="padding: 1rem 1.5rem; font-size: 0.9rem; color: var(--text-primary);"><?php echo $i + 1; ?></td>
                                    <td style="padding: 1rem 1.5rem; font-size: 0.9rem; color: var(--text-primary);"><?php echo htmlspecialchars($sub['student_id']); ?></td>
                                    <td style="padding: 1rem 1.5rem; font-size: 0.9rem; color: var(--text-primary);"><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                    <td style="padding: 1rem 1.5rem; font-size: 0.9rem;">
                                        <div style="display: flex; gap: 0.5rem; flex-direction: column;">
                                            <?php
                                            $raw_file = $sub['file_path'] ?? $sub['file'] ?? '';
                                            $decoded_paths = json_decode($raw_file, true);
                                            $paths = is_array($decoded_paths) ? $decoded_paths : explode(',', $raw_file);
                                            foreach ($paths as $idx => $path):
                                                $path = trim($path);
                                                $path = preg_replace('#^uploads/#i', '', $path);
                                                if (empty($path)) continue;
                                            ?>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <a href="uploads/<?php echo htmlspecialchars($path); ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 0.35rem; color: #0284c7; font-weight: 600; text-decoration: none;">
                                                    <i class="fa-solid fa-paperclip"></i> View <?php echo count($paths) > 1 ? 'Document ' . ($idx + 1) : 'Document'; ?>
                                                </a>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 1.5rem; text-align: left;">
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <form method="POST" action="faculty_dashboard.php" style="margin: 0; display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <input type="hidden" name="action" value="grade_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo $sub['id']; ?>">
                                                <input type="hidden" name="status" value="Graded">
                                                <input type="text" name="marks" value="<?php echo htmlspecialchars(trim(explode('/', $sub['marks'] ?? '')[0]) === 'Pending' ? '' : trim(explode('/', $sub['marks'] ?? '')[0])); ?>" required style="width: 50px; padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 4px; text-align: center; font-family: inherit; font-size: 0.9rem;">
                                                <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 600;">/ 10</span>
                                                <button type="submit" style="padding: 0.4rem 0.85rem; border: none; border-radius: 4px; background-color: #3b82f6; color: white; cursor: pointer; font-weight: 600; font-size: 0.85rem;">Save</button>
                                            </form>
                                            <form method="POST" action="faculty_dashboard.php" style="margin: 0; display: inline-flex; align-items: center;">
                                                <input type="hidden" name="action" value="grade_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo $sub['id']; ?>">
                                                <input type="hidden" name="status" value="Rejected">
                                                <input type="hidden" name="marks" value="0">
                                                <button type="submit" style="padding: 0.4rem 0.85rem; border: none; border-radius: 4px; background-color: #ef4444; color: white; cursor: pointer; font-weight: 600; font-size: 0.85rem;" onclick="return confirm('Reject this submission? The student will be asked to resubmit.');">Reject</button>
                                            </form>
                                        </div>
                                        <?php if (($sub['status'] ?? '') === 'Rejected'): ?>
                                        <div style="margin-top: 0.5rem; font-size: 0.8rem; font-weight: 600; color: #ef4444;">
                                            <i class="fa-solid fa-circle-xmark"></i> Rejected
                                        </div>
                                        <?php endif; ?>
                                    </td>

                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div style="padding: 2.5rem; text-align: center; color: var(--text-muted); border-top: 1px solid var(--border-color);">
                        <div style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem;">
                            <i class="fa-solid fa-inbox"></i>
                        </div>
                        <p style="font-size: 0.95rem;">No student submissions yet for this assignment.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php 
                    endforeach; 
                endif; 
                ?>

                <!-- JavaScript for Interactive Filters -->
                <script>
                document.addEventListener('DOMContentLoaded', () => {
                    let activeYear = '1';
                    let activeSem = '1';
                    let activeDiv = 'A';

                    const yearBtns = document.querySelectorAll('.filter-year-btn');
                    const semBtns = document.querySelectorAll('.filter-sem-btn');
                    const divBtns = document.querySelectorAll('.filter-div-btn');
                    const assignmentCards = document.querySelectorAll('.assignment-card-item');

                    function updateFilters() {
                        // Year buttons styling
                        yearBtns.forEach(btn => {
                            if (btn.getAttribute('data-year') === activeYear) {
                                btn.style.color = '#4f46e5';
                                btn.style.fontWeight = '700';
                                btn.style.borderBottom = '2px solid #4f46e5';
                            } else {
                                btn.style.color = '#64748b';
                                btn.style.fontWeight = '600';
                                btn.style.borderBottom = 'none';
                            }
                        });

                        // Semester buttons styling
                        semBtns.forEach(btn => {
                            if (btn.getAttribute('data-sem') === activeSem) {
                                btn.style.background = '#f5f3ff';
                                btn.style.color = '#8b5cf6';
                                btn.style.borderColor = '#c084fc';
                                btn.style.fontWeight = '700';
                            } else {
                                btn.style.background = 'white';
                                btn.style.color = '#64748b';
                                btn.style.borderColor = '#cbd5e1';
                                btn.style.fontWeight = '600';
                            }
                        });

                        // Division buttons styling
                        divBtns.forEach(btn => {
                            if (btn.getAttribute('data-div') === activeDiv) {
                                btn.style.background = '#3b82f6';
                                btn.style.color = 'white';
                                btn.style.fontWeight = '700';
                            } else {
                                btn.style.background = '#f1f5f9';
                                btn.style.color = '#475569';
                                btn.style.fontWeight = '600';
                            }
                        });

                        // Filter cards visibility
                        let visibleCount = 0;
                        assignmentCards.forEach(card => {
                            const cardYear = card.getAttribute('data-year');
                            const cardSem = card.getAttribute('data-semester');
                            const cardDiv = card.getAttribute('data-division');

                            if (cardYear === activeYear && cardSem === activeSem && cardDiv === activeDiv) {
                                card.style.display = 'block';
                                visibleCount++;
                            } else {
                                card.style.display = 'none';
                            }
                        });

                        // Empty filter message
                        let noAssignmentsMsg = document.getElementById('noAssignmentsFilteredMsg');
                        if (!noAssignmentsMsg) {
                            noAssignmentsMsg = document.createElement('div');
                            noAssignmentsMsg.id = 'noAssignmentsFilteredMsg';
                            noAssignmentsMsg.style.cssText = 'padding: 3rem; text-align: center; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color); color: var(--text-muted); margin-top: 1rem;';
                            noAssignmentsMsg.innerHTML = '<div style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem;"><i class="fa-solid fa-inbox"></i></div><p style="font-size: 0.95rem;"></p>';
                            const parent = document.getElementById('tab-assignments');
                            parent.appendChild(noAssignmentsMsg);
                        }

                        if (visibleCount === 0) {
                            noAssignmentsMsg.querySelector('p').textContent = 'No assignments published for Year ' + activeYear + ', Semester ' + activeSem + ', Division ' + activeDiv + '.';
                            noAssignmentsMsg.style.display = 'block';
                        } else {
                            noAssignmentsMsg.style.display = 'none';
                        }
                    }

                    yearBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            activeYear = btn.getAttribute('data-year');
                            updateFilters();
                        });
                    });

                    semBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            activeSem = btn.getAttribute('data-sem');
                            updateFilters();
                        });
                    });

                    divBtns.forEach(btn => {
                        btn.addEventListener('click', () => {
                            activeDiv = btn.getAttribute('data-div');
                            updateFilters();
                        });
                    });

                    // Initial run
                    updateFilters();
                });
                </script>
            </div>

            <!-- ============================================ -->
            <!-- NOTICES TAB                                  -->
            <!-- ============================================ -->
            <div id="tab-notices" class="app-view">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h2 style="font-size: 2.25rem; color: #3b82f6; font-weight: 800; margin-bottom: 0.5rem;">Publish Notice</h2>
                    <p style="color: var(--text-muted);">Post announcements and broadcast updates to everyone.</p>
                </div>
                
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 3rem; box-shadow: var(--box-shadow-subtle);">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="publish_notice">
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Notice Title</label>
                            <input type="text" name="title" required placeholder="e.g. Extra Class Scheduled" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem;">
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Description</label>
                            <textarea name="desc" rows="4" required placeholder="Enter notice details..." style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem; resize: vertical;"></textarea>
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Expiry Date (Optional)</label>
                            <input type="date" name="expiry" min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem;">
                        </div>
                        
                        <div style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 2rem; background: var(--bg-page); margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                            <div style="display: flex; align-items: center; gap: 2rem;">
                                <div style="width: 56px; height: 56px; background: #dbeafe; color: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                                    <i class="fa-solid fa-paperclip"></i>
                                </div>
                                <div style="text-align: left;">
                                    <h4 style="font-weight: 600; margin-bottom: 0.25rem; font-size: 1.05rem; color: var(--text-primary);">Attach File (Optional)</h4>
                                    <p style="font-size: 0.9rem; color: var(--text-muted);">Click here to <label for="notice-file-upload" style="color: #3b82f6; font-weight: 600; cursor: pointer;">browse</label> and select a file</p>
                                    <input id="notice-file-upload" type="file" name="attachment" style="display: none;">
                                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">Supported formats: PDF, DOCX, JPG, PNG (Max 5MB)</p>
                                </div>
                            </div>
                            <label for="notice-file-upload" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.65rem 1.25rem; border-radius: 6px; color: #3b82f6; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background 0.2s;">
                                <i class="fa-solid fa-arrow-up-from-bracket"></i> Choose File
                            </label>
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" style="background: #3b82f6; color: white; border: none; padding: 0.85rem 1.75rem; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 1rem; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2); transition: transform 0.2s, box-shadow 0.2s;">Publish Notice</button>
                        </div>
                    </form>
                </div>
                
                <h3 style="font-size: 1.35rem; font-weight: 700; margin-top: 3rem; margin-bottom: 1.5rem; color: var(--text-primary);">Published Notices</h3>
                
                <?php 
                foreach ($db['notices'] as $n): 
                    if ($n['author'] !== $current_faculty['name']) continue;
                ?>
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 1.5rem; box-shadow: var(--box-shadow-subtle); overflow: hidden;">
                    <div style="padding: 1.5rem; display: flex; gap: 2rem; align-items: flex-start;">
                        <div style="width: 48px; height: 48px; background: var(--bg-card);1f2; color: #e11d48; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.35rem; color: var(--text-primary);"><?= htmlspecialchars($n['title']) ?></h4>
                            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0.65rem;"><?= htmlspecialchars($n['desc']) ?></p>
                            <div style="display: flex; align-items: center; gap: 2rem; font-size: 0.85rem; color: var(--text-secondary); font-weight: 500; flex-wrap: wrap;">
                                <span><i class="fa-regular fa-calendar" style="color: var(--text-secondary);"></i> Published: <?= htmlspecialchars($n['date']) ?></span>
                                <span><i class="fa-regular fa-clock" style="color: var(--text-secondary);"></i> Expiry: <?= htmlspecialchars($n['expiry'] ?: 'N/A') ?></span>
                                <?php if (!empty($n['attachment'])): ?>
                                    <a href="<?= htmlspecialchars($n['attachment']) ?>" target="_blank" style="color: #0284c7; text-decoration: none;"><i class="fa-solid fa-paperclip"></i> <?= htmlspecialchars($n['attachment']) ?></a>
                                <?php endif; ?>
                                <form method="POST" action="delete.php" style="margin:0; margin-left:auto;">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="type" value="notices">
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                    <button type="submit" style="background:transparent;border:none;color:#ef4444;cursor:pointer;padding:0.2rem;" title="Delete Notice" onclick="return confirm('Delete this notice?');"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
            </div>

            <!-- ============================================ -->
            <!-- GRIEVANCES TAB                               -->
            <!-- ============================================ -->
            <div id="tab-grievances" class="app-view">
                <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary);">Assignment Document Grievances</h3>
                
                <!-- Filters for Assignment Grievances -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; box-shadow: var(--box-shadow-subtle);">
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.35rem;">Filter by Subject</label>
                        <select id="facGrievanceSubjectFilter" onchange="filterFacultyGrievances()" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 0.9rem; background: var(--bg-card);">
                            <option value="all">All Subjects</option>
                            <?php foreach ($faculty_subjects as $fs): ?>
                                <option value="<?php echo htmlspecialchars($fs); ?>"><?php echo htmlspecialchars($fs); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.35rem;">Filter by Status</label>
                        <select id="facGrievanceStatusFilter" onchange="filterFacultyGrievances()" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 0.9rem; background: var(--bg-card);">
                            <option value="all">All Statuses</option>
                            <option value="Pending">Pending</option>
                            <option value="In Review">In Review</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                </div>

                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; overflow-x: auto; box-shadow: var(--box-shadow-subtle); margin-bottom: 3rem;">
                    <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
                        <thead style="background: var(--bg-page); font-size: 0.85rem; color: var(--text-primary); font-weight: 600;">
                            <tr>
                                <th style="padding: 1.25rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border-color); width: 60px;">#</th>
                                <th style="padding: 1.25rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border-color); width: 220px;">Student Details</th>
                                <th style="padding: 1.25rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border-color); width: 240px;">Assignment Info</th>
                                <th style="padding: 1.25rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Issue & Description</th>
                                <th style="padding: 1.25rem 1.5rem; text-align: left; border-bottom: 1px solid var(--border-color); width: 320px;">Faculty Actions & Response</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $assign_grievances = array_reverse($db['assignment_grievances'] ?? []);
                            $my_assign_grievances = [];
                            foreach ($assign_grievances as $g) {
                                $sa_item = null;
                                foreach ($db['subject_assignments'] as $sa) {
                                    if ($sa['id'] == $g['subject_assignment_id']) {
                                        $sa_item = $sa;
                                        break;
                                    }
                                }
                                if ($sa_item && in_array($sa_item['subject_name'], $faculty_subjects)) {
                                    // Fetch student info
                                    $student_info = null;
                                    foreach ($db['students'] as $stu) {
                                        if ($stu['id'] === $g['student_id']) {
                                            $student_info = $stu;
                                            break;
                                        }
                                    }
                                    $g['student_info'] = $student_info;
                                    $my_assign_grievances[] = $g;
                                }
                            }
                            if (empty($my_assign_grievances)):
                            ?>
                                <tr>
                                    <td colspan="5" style="padding: 2rem; text-align: center; color: var(--text-muted);">No assignment grievances submitted yet.</td>
                                </tr>
                            <?php 
                            else:
                                foreach ($my_assign_grievances as $idx => $g): 
                                    // Find subject assignment
                                    $sa_item = null;
                                    foreach ($db['subject_assignments'] as $sa) {
                                        if ($sa['id'] == $g['subject_assignment_id']) {
                                            $sa_item = $sa;
                                            break;
                                        }
                                    }
                                    $subject_name = $sa_item ? $sa_item['subject_name'] : 'Unknown Subject';
                                    $assign_title = $sa_item ? $sa_item['assignment_title'] : 'Unknown Assignment';
                                    
                                    $stu = $g['student_info'] ?? null;
                                    $stu_semester = $stu ? $stu['semester'] : 'N/A';
                                    $stu_div = $stu ? $stu['division'] : 'N/A';
                            ?>
                            <tr class="assignment-grievance-row" data-subject="<?= htmlspecialchars($subject_name) ?>" data-status="<?= htmlspecialchars($g['status'] ?? 'Pending') ?>" style="border-bottom: 1px solid var(--border-color); vertical-align: top;">
                                <td style="padding: 1.25rem 1.5rem; font-size: 0.95rem; color: var(--text-primary);"><?= $idx + 1 ?></td>
                                <td style="padding: 1.25rem 1.5rem;">
                                    <div style="font-weight: 600; color: var(--text-primary); font-size: 0.9rem;"><?= htmlspecialchars($g['student_name']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.15rem;">
                                        PRN: <?= htmlspecialchars($g['student_id']) ?> | Div: <?= htmlspecialchars($stu_div) ?><br>
                                        <?= htmlspecialchars($stu_semester) ?>
                                    </div>
                                </td>
                                <td style="padding: 1.25rem 1.5rem;">
                                    <div style="font-weight: 600; color: var(--text-primary); font-size: 0.9rem; margin-bottom: 0.15rem;"><?= htmlspecialchars($subject_name) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($assign_title) ?></div>
                                </td>
                                <td style="padding: 1.25rem 1.5rem;">
                                    <div style="font-weight: 700; color: #b91c1c; font-size: 0.85rem; margin-bottom: 0.25rem;">Issue: <?= htmlspecialchars($g['issue_type']) ?></div>
                                    <div style="font-size: 0.85rem; color: var(--text-primary); line-height: 1.4; margin-bottom: 0.5rem;"><?= nl2br(htmlspecialchars($g['description'])) ?></div>
                                    <?php if (!empty($g['screenshot'])): ?>
                                        <div>
                                            <a href="uploads/<?= htmlspecialchars($g['screenshot']) ?>" target="_blank" style="color: #3b82f6; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fa-regular fa-image"></i> View Screenshot
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.25rem 1.5rem; width: 340px;">
                                    <div style="background: var(--bg-page); border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem; display: flex; flex-direction: column; gap: 0.85rem;">
                                        <!-- Reply & Status update form -->
                                        <form method="POST" action="faculty_dashboard.php" style="margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                                            <input type="hidden" name="action" value="respond_assignment_grievance">
                                            <input type="hidden" name="grievance_id" value="<?= $g['id'] ?>">
                                            
                                            <div>
                                                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.5px;">Faculty Response</label>
                                                <textarea name="reply" rows="2" placeholder="Write response to student..." style="width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.85rem; font-family: inherit; resize: vertical; background: var(--bg-card); outline: none; transition: all 0.2s;" required><?= htmlspecialchars($g['reply'] ?? '') ?></textarea>
                                            </div>
                                            
                                            <div style="display: flex; gap: 0.5rem; align-items: center; justify-content: space-between;">
                                                <select name="status" style="flex: 1; padding: 0.45rem 0.6rem; font-size: 0.82rem; font-weight: 600; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); cursor: pointer; outline: none;">
                                                    <option value="Pending" <?= ($g['status'] === 'Pending') ? 'selected' : '' ?>>🟡 Pending</option>
                                                    <option value="In Review" <?= ($g['status'] === 'In Review') ? 'selected' : '' ?>>🔵 In Review</option>
                                                    <option value="Resolved" <?= ($g['status'] === 'Resolved') ? 'selected' : '' ?>>🟢 Resolved</option>
                                                    <option value="Rejected" <?= ($g['status'] === 'Rejected') ? 'selected' : '' ?>>🔴 Rejected</option>
                                                </select>
                                                
                                                <button type="submit" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.45rem 0.85rem; border-radius: 6px; font-size: 0.8rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(16,185,129,0.2); transition: all 0.2s;">
                                                    <i class="fa-solid fa-paper-plane"></i> Save
                                                </button>
                                            </div>
                                        </form>

                                        <!-- Replace PDF option -->
                                        <form method="POST" action="faculty_dashboard.php" enctype="multipart/form-data" style="margin: 0; padding-top: 0.75rem; border-top: 1px dashed #cbd5e1;">
                                            <input type="hidden" name="action" value="replace_question_pdf">
                                            <input type="hidden" name="subject_assignment_id" value="<?= $g['subject_assignment_id'] ?>">
                                            
                                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid fa-file-arrow-up" style="color: #6366f1;"></i> Replace Question File
                                            </div>
                                            
                                            <div style="display: flex; gap: 0.4rem; align-items: center;">
                                                <input type="file" name="new_question_pdf" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" required style="font-size: 0.75rem; color: var(--text-secondary); width: 170px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.25rem 0.4rem;">
                                                <button type="submit" style="background: linear-gradient(135deg, #4f46e5, #4338ca); color: white; border: none; padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(79,70,229,0.2); transition: all 0.2s;">
                                                    <i class="fa-solid fa-upload"></i> Upload
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endforeach; 
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>


            <!-- Grievance Details Modal -->
            <div id="grievanceModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; justify-content: center; align-items: center; opacity: 0; transition: opacity 0.3s ease;">
                <div class="modal-content" style="background: var(--bg-card); width: 100%; max-width: 500px; border-radius: 16px; padding: 30px; transform: translateY(20px); transition: transform 0.3s ease; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color);">
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">Grievance Details</h3>
                        <button onclick="closeGrievanceModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--text-secondary); cursor: pointer; transition: color 0.2s;"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.5px;">Student Name & ID</div>
                        <div id="modal-g-student" style="font-size: 1rem; color: var(--text-primary); font-weight: 600;"></div>
                    </div>
                    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                        <div style="flex: 1;">
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.5px;">Category</div>
                            <div id="modal-g-category" style="font-size: 0.95rem; color: var(--text-primary); font-weight: 500;"></div>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.5px;">Date Submitted</div>
                            <div id="modal-g-date" style="font-size: 0.95rem; color: var(--text-primary); font-weight: 500;"></div>
                        </div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.5px;">Title</div>
                        <div id="modal-g-title" style="font-size: 0.95rem; color: var(--text-primary); font-weight: 600;"></div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 0.5px;">Description</div>
                        <div id="modal-g-desc" style="font-size: 0.95rem; color: var(--text-secondary); line-height: 1.5; background: var(--bg-page); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); white-space: pre-wrap;"></div>
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button onclick="closeGrievanceModal()" style="background: #e2e8f0; color: var(--text-secondary); border: none; padding: 0.6rem 1.25rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s;">Close</button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript code for navigation -->
    <script>
        function showGrievanceDetails(g) {
            document.getElementById('modal-g-student').textContent = g.student_name + ' (' + g.student_id + ')';
            document.getElementById('modal-g-category').textContent = g.category;
            document.getElementById('modal-g-date').textContent = g.date;
            document.getElementById('modal-g-title').textContent = g.title;
            document.getElementById('modal-g-desc').textContent = g.desc || 'No description provided.';
            
            const modal = document.getElementById('grievanceModal');
            modal.style.display = 'flex';
            // Trigger reflow
            void modal.offsetWidth;
            modal.style.opacity = '1';
            modal.querySelector('.modal-content').style.transform = 'translateY(0)';
        }
        
        function closeGrievanceModal() {
            const modal = document.getElementById('grievanceModal');
            modal.style.opacity = '0';
            modal.querySelector('.modal-content').style.transform = 'translateY(20px)';
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('grievanceModal');
            if (e.target === modal) {
                closeGrievanceModal();
            }
        });
        function switchTab(tabName, element) {
            const items = document.querySelectorAll('.sidebar-nav-item');
            items.forEach(item => item.classList.remove('active'));
            if (element) {
                element.classList.add('active');
            } else {
                items.forEach(item => {
                    let onclick = item.getAttribute('onclick') || '';
                    let dataTab = item.getAttribute('data-tab') || '';
                    if (onclick.includes("'" + tabName + "'") || dataTab === tabName) {
                        item.classList.add('active');
                    }
                });
            }

            const panels = document.querySelectorAll('.app-view');
            panels.forEach(p => p.classList.remove('active'));

            const headerTitle = document.getElementById('currentTabTitle');
            const headerSubtitle = document.getElementById('currentTabSubtitle');

            // Show selected panel
            if (tabName === 'leaves') {
                document.getElementById('tab-leaves').classList.add('active');
                headerTitle.textContent = "Leave Approvals";
                headerSubtitle.textContent = "Manage and respond to student leave requests.";
            } else if (tabName === 'assignments') {
                document.getElementById('tab-assignments').classList.add('active');
                headerTitle.textContent = "Manage Assignments";
                headerSubtitle.textContent = "Create assignments and grade student submissions.";
            } else if (tabName === 'notices') {
                document.getElementById('tab-notices').classList.add('active');
                headerTitle.textContent = "Publish Notices";
                headerSubtitle.textContent = "Create and broadcast important announcements to students.";
            } else if (tabName === 'grievances') {
                document.getElementById('tab-grievances').classList.add('active');
                headerTitle.textContent = "Grievance";
                headerSubtitle.textContent = "Review and address student issues and complaints.";
            } else if (tabName === 'dashboard') {
                document.getElementById('tab-dashboard').classList.add('active');
                headerTitle.textContent = "Dashboard";
                headerSubtitle.textContent = "Quick access to all essential faculty services.";
            } else if (tabName === 'attendance') {
                document.getElementById('tab-attendance').classList.add('active');
                headerTitle.textContent = "Mark Attendance";
                headerSubtitle.textContent = "Select your teaching class and mark student lecture attendance.";
            } else if (tabName === 'profile') {
                document.getElementById('tab-profile').classList.add('active');
                headerTitle.textContent = "My Profile";
                headerSubtitle.textContent = "View and manage your professional credentials.";
            }
        }
    </script>
    <script>
        function markAllAttendance(status) {
            document.querySelectorAll(`input[type="radio"][value="${status}"]`).forEach(radio => {
                radio.checked = true;
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            // File upload logic for faculty assignment
            let selectedFacultyFile = null;
            const dropZone = document.getElementById('facultyDropZone');
            const fileInput = document.getElementById('file-upload');
            const uploadTitleText = document.getElementById('upload-title-text');
            const uploadStatusText = document.getElementById('upload-status-text');
            const uploadAllowedText = document.getElementById('upload-allowed-text');
            const dynamicBtnArea = document.getElementById('dynamic-btn-area');
            const publishForm = document.getElementById('publishAssignmentForm');
            const progressContainer = document.getElementById('uploadProgressContainer');
            const progressBarFill = document.getElementById('progressBarFill');
            const progressPercentText = document.getElementById('progressPercentText');
            const publishBtn = document.getElementById('btnPublishAssignment');

            function showToastNotification(message, type = 'success') {
                const toast = document.createElement('div');
                toast.className = `toast-notification toast-${type}`;
                toast.style.position = 'fixed';
                toast.style.top = '20px';
                toast.style.right = '20px';
                toast.style.zIndex = '99999';
                toast.style.display = 'flex';
                toast.style.alignItems = 'center';
                toast.style.gap = '0.5rem';
                toast.style.background = type === 'success' ? '#10b981' : '#ef4444';
                toast.style.color = 'white';
                toast.style.padding = '0.75rem 1.5rem';
                toast.style.borderRadius = '8px';
                toast.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.1)';
                toast.style.transition = 'opacity 0.3s ease';
                toast.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'}"></i><span>${message}</span>`;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }

            if (dropZone && fileInput) {
                // Prevent default drag behaviors
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, e => {
                        e.preventDefault();
                        e.stopPropagation();
                    }, false);
                });

                // Highlight/unhighlight drop zone
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => {
                        dropZone.style.borderColor = '#4f46e5';
                        dropZone.style.background = '#f5f3ff';
                    }, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => {
                        dropZone.style.borderColor = '#cbd5e1';
                        dropZone.style.background = '#f8fafc';
                    }, false);
                });

                // Handle dropped files
                dropZone.addEventListener('drop', e => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files.length > 0) {
                        validateAndSetFile(files[0]);
                    }
                });

                // Handle file input selection
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        validateAndSetFile(this.files[0]);
                    }
                });

                // Click zone to trigger file browser
                dropZone.addEventListener('click', e => {
                    if (e.target.closest('#btn-remove-file') || e.target.closest('#btn-change-file')) {
                        return; // Let button click handlers handle it
                    }
                    fileInput.click();
                });

                function validateAndSetFile(file) {
                    const ext = file.name.split('.').pop().toLowerCase();
                    const allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    if (!allowedExts.includes(ext)) {
                        showToastNotification('Unsupported file type. Only PDF, DOC, DOCX, JPG, JPEG, and PNG are allowed.', 'error');
                        resetFileSelection();
                        return;
                    }

                    if (file.size > 10 * 1024 * 1024) {
                        showToastNotification('File is too large. Maximum size is 10 MB.', 'error');
                        resetFileSelection();
                        return;
                    }

                    selectedFacultyFile = file;
                    
                    // Update UI
                    uploadTitleText.textContent = file.name;
                    const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    uploadStatusText.innerHTML = `<span style="color: #10b981; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> File selected successfully.</span> (${sizeMB} MB, Type: ${ext.toUpperCase()})`;
                    
                    dynamicBtnArea.innerHTML = `
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" id="btn-change-file" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; color: #4f46e5; font-weight: 600; cursor: pointer; font-size: 0.85rem;"><i class="fa-solid fa-arrows-rotate"></i> Change File</button>
                            <button type="button" id="btn-remove-file" style="background: #fee2e2; border: 1px solid #fecaca; padding: 0.5rem 1rem; border-radius: 6px; color: #b91c1c; font-weight: 600; cursor: pointer; font-size: 0.85rem;"><i class="fa-solid fa-trash-can"></i> Remove</button>
                        </div>
                    `;

                    // Bind change/remove buttons
                    document.getElementById('btn-change-file').addEventListener('click', (e) => {
                        e.stopPropagation();
                        fileInput.click();
                    });

                    document.getElementById('btn-remove-file').addEventListener('click', (e) => {
                        e.stopPropagation();
                        resetFileSelection();
                    });
                }

                function resetFileSelection() {
                    selectedFacultyFile = null;
                    fileInput.value = '';
                    uploadTitleText.textContent = "Upload Question File *";
                    uploadStatusText.innerHTML = 'Click here to <span style="color: #4f46e5; font-weight: 600;">browse</span> and select a file';
                    dynamicBtnArea.innerHTML = `
                        <label for="file-upload" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.65rem 1.25rem; border-radius: 6px; color: #4f46e5; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background 0.2s;">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Choose File
                        </label>
                    `;
                }
            }

            // Form Submit via AJAX
            if (publishForm) {
                publishForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (!selectedFacultyFile) {
                        showToastNotification('Please select a valid question file first.', 'error');
                        return;
                    }

                    publishBtn.disabled = true;
                    publishBtn.style.opacity = '0.7';
                    progressContainer.style.display = 'block';
                    progressBarFill.style.width = '0%';
                    progressPercentText.textContent = '0%';

                    const formData = new FormData(this);
                    formData.set('assignment_file', selectedFacultyFile);

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'faculty_dashboard.php', true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            progressBarFill.style.width = percent + '%';
                            progressPercentText.textContent = percent + '%';
                        }
                    });

                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === XMLHttpRequest.DONE) {
                            publishBtn.disabled = false;
                            publishBtn.style.opacity = '1';
                            progressContainer.style.display = 'none';

                            if (xhr.status === 200) {
                                try {
                                    const res = JSON.parse(xhr.responseText);
                                    if (res.success) {
                                        showToastNotification('✓ Assignment published successfully.', 'success');
                                        setTimeout(() => {
                                            window.location.href = 'faculty_dashboard.php?tab=assignments';
                                        }, 1500);
                                    } else {
                                        showToastNotification(res.message || 'Upload failed.', 'error');
                                    }
                                } catch(err) {
                                    showToastNotification('Server response parse failed.', 'error');
                                }
                            } else {
                                try {
                                    const res = JSON.parse(xhr.responseText);
                                    showToastNotification(res.message || 'An error occurred during publication.', 'error');
                                } catch(err) {
                                    showToastNotification('Server error: ' + xhr.statusText, 'error');
                                }
                            }
                        }
                    };

                    xhr.send(formData);
                });
            }

            document.querySelectorAll('form[action="delete.php"]').forEach(form => {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    try {
                        let formData = new FormData(this);
                        let response = await fetch('delete.php', {
                            method: 'POST',
                            body: formData
                        });
                        if (response.ok) {
                            window.location.reload();
                        } else {
                            alert('Failed to delete item.');
                        }
                    } catch (err) {
                        console.error(err);
                        alert('An error occurred while deleting.');
                    }
                });
            });
        });

        // Dark mode toggle handler
        function toggleDarkMode() {
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme_preference', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark);
        }

        function updateThemeIcon(isDark) {
            const btns = document.querySelectorAll('.theme-toggle-btn');
            btns.forEach(btn => {
                btn.innerHTML = isDark 
                    ? '<i class="fa-solid fa-sun" style="color: #f59e0b;"></i>' 
                    : '<i class="fa-solid fa-moon"></i>';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme_preference') === 'dark') {
                document.body.classList.add('dark-mode');
                updateThemeIcon(true);
            }
        });

        function filterFacultyGrievances() {
            const subjectVal = document.getElementById('facGrievanceSubjectFilter').value;
            const statusVal = document.getElementById('facGrievanceStatusFilter').value;
            
            document.querySelectorAll('.assignment-grievance-row').forEach(row => {
                const matchSub = (subjectVal === 'all' || row.getAttribute('data-subject') === subjectVal);
                const matchStatus = (statusVal === 'all' || row.getAttribute('data-status') === statusVal);
                
                if (matchSub && matchStatus) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        function toggleFacultyPreview(path, type, divId) {
            const pane = document.getElementById(divId);
            if (!pane) return;
            if (pane.style.display === 'block') {
                pane.style.display = 'none';
                pane.innerHTML = '';
            } else {
                pane.style.display = 'block';
                const ext = type.toLowerCase();
                if (['jpg', 'jpeg', 'png'].includes(ext)) {
                    pane.innerHTML = `<img src="${path}" style="max-width:100%; max-height:200px; object-fit:contain; border-radius:4px;">`;
                } else if (ext === 'pdf') {
                    pane.innerHTML = `<iframe src="${path}" style="width:100%; height:200px; border:none; border-radius:4px;"></iframe>`;
                } else {
                    pane.innerHTML = `<div style="text-align:center; font-size:0.75rem; color: var(--text-secondary); padding:1rem;"><i class="fa-solid fa-file-word" style="font-size:2rem; color:#2b579a; display:block; margin-bottom:0.25rem;"></i> Preview unavailable for ${ext.toUpperCase()}</div>`;
                }
            }
        }
    </script>
</body>
</html>
