<?php
session_start();
require_once 'db.php';

// Authentication check
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'hod') {
    header("Location: login.php?role=hod");
    exit;
}

$user = $_SESSION['user'];
$db = get_db();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // -- LEAVE ACTIONS --
    if ($action === 'approve_leave' || $action === 'reject_leave') {
        $leave_id = intval($_POST['leave_id']);
        $new_status = ($action === 'approve_leave') ? 'Approved' : 'Rejected';
        
        $updated = false;
        foreach ($db['leaves'] as &$leave) {
            if ($leave['id'] === $leave_id) {
                $leave['status'] = $new_status;
                $updated = true;
                break;
            }
        }
        if ($updated) {
            save_db($db);
            $_SESSION['success_message'] = "Leave #$leave_id has been $new_status.";
        } else {
            $_SESSION['error_message'] = "Leave request not found.";
        }
        header("Location: hod_dashboard.php");
        exit;
    }
    
    // -- SETTINGS --
    elseif ($action === 'save_settings') {
        $db['settings']['dept_name'] = $_POST['dept_name'] ?? $db['settings']['dept_name'];
        $db['settings']['dept_code'] = $_POST['dept_code'] ?? $db['settings']['dept_code'];
        $db['settings']['hod_name']  = $_POST['hod_name'] ?? $db['settings']['hod_name'];
        $db['settings']['hod_email'] = $_POST['hod_email'] ?? $db['settings']['hod_email'];
        $db['settings']['maintenance_mode'] = isset($_POST['maintenance_mode']);
        
        save_db($db);
        $_SESSION['success_message'] = "Settings updated successfully.";
        header("Location: hod_dashboard.php");
        exit;
    }
    
    // -- NOTICES --
    elseif ($action === 'publish_notice') {
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
            $db['notices'][] = [
                'id' => count($db['notices']) + 1,
                'title' => $title,
                'desc' => $desc,
                'author' => $user['name'],
                'role' => 'Head of Department',
                'target_audience' => $user['dept'],
                'date' => date('d M Y'),
                'expiry' => $expiry,
                'attachment' => $file_name,
                'size' => $file_name ? '1.5MB' : ''
            ];
            
            $db['recent_activity'] = array_merge([
                [
                    'title' => 'Notice Published',
                    'desc' => 'Department Head published a new notice: ' . $title,
                    'time' => 'Just now'
                ]
            ], array_slice($db['recent_activity'], 0, 3));
            
            save_db($db);
            $_SESSION['success_message'] = "Notice published successfully.";
        } else {
            $_SESSION['error_message'] = "Title and Description are required.";
        }
        header("Location: hod_dashboard.php");
        exit;
    }
    
    // -- RESOLVE GRIEVANCE --
    elseif ($action === 'resolve_grievance') {
        $g_id = intval($_POST['grievance_id']);
        $updated = false;
        foreach ($db['grievances'] as &$g) {
            if ($g['id'] === $g_id) {
                $g['status'] = 'Resolved';
                $updated = true;
                $g_title = $g['subject'];
                break;
            }
        }
        if ($updated) {
            $db['recent_activity'] = array_merge([
                [
                    'type' => 'grievance',
                    'title' => 'Grievance Resolved',
                    'desc' => "Your grievance '{$g_title}' has been resolved.",
                    'time' => 'Just now'
                ]
            ], array_slice($db['recent_activity'] ?? [], 0, 9));
            save_db($db);
            $_SESSION['success_message'] = "Grievance marked as resolved.";
        }
        header("Location: hod_dashboard.php");
        exit;
    }
    // -- GRIEVANCE REPLY --
    elseif ($action === 'reply_grievance') {
        $grievance_id = intval($_POST['grievance_id']);
        $reply_msg = trim($_POST['reply_msg']);
        $new_status = trim($_POST['status']);
        
        if (!empty($reply_msg)) {
            $updated = false;
            foreach ($db['grievances'] as &$g) {
                if ($g['id'] === $grievance_id) {
                    $g['status'] = $new_status;
                    $g['replies'][] = [
                        'author' => $user['name'],
                        'role' => 'Head of Department',
                        'date' => date('d M Y h:i A'),
                        'message' => $reply_msg
                    ];
                    $updated = true;
                    $g_title = $g['subject'];
                    break;
                }
            }
            if ($updated) {
                $db['recent_activity'] = array_merge([
                    [
                        'type' => 'grievance',
                        'title' => 'Grievance Update',
                        'desc' => "New reply on your grievance '{$g_title}'",
                        'time' => 'Just now'
                    ]
                ], array_slice($db['recent_activity'] ?? [], 0, 9));
                save_db($db);
                $_SESSION['success_message'] = "Reply sent and status updated.";
            } else {
                $_SESSION['error_message'] = "Grievance not found.";
            }
        }
        header("Location: hod_dashboard.php");
        exit;
    }
    
    // -- ASSIGNMENT GRADING --
    // Grading moved to faculty dashboard. HOD only views assignments.
    // -- REPORTS --
    elseif ($action === 'download_report') {
        $type = $_POST['report_type'];
        $sem = $_POST['semester'];
        
        // Output CSV and exit
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        
        if ($type === 'Attendance Defaulters') {
            fputcsv($output, ['Student Name', 'ID', 'Department', 'Semester', 'Attendance']);
            foreach ($db['students'] as $s) {
                $st_info = parse_student_dept_info($s['dept'] ?? $s['department'] ?? '');
                if (intval($s['attendance']) < 75 && $s['semester'] == $sem && match_department($st_info['department'], $user['dept'])) {
                    fputcsv($output, [$s['name'], $s['id'], $s['dept'], $s['semester'], $s['attendance']]);
                }
            }
        } elseif ($type === 'Grievance Summary') {
            fputcsv($output, ['Title', 'Student', 'Category', 'Status', 'Date']);
            foreach ($db['grievances'] as $g) {
                if (isset($g['department']) && !match_department($g['department'], $user['dept'])) continue; // Only filter if department is known
                fputcsv($output, [$g['title'], $g['student_name'], $g['category'], $g['status'], $g['date']]);
            }
        } else {
            fputcsv($output, ['Report Type', 'Semester']);
            fputcsv($output, [$type, $sem]);
        }
        
        fclose($output);
        exit;
    }
}

// Calculators for dashboard stats
$total_students = count($db['students']);
$total_faculty = count($db['faculty']);
$total_notices = 0;
foreach ($db['notices'] as $n) {
    $target = $n['target_audience'] ?? 'All Departments';
    if ($target === 'All Departments' || $target === 'Faculty Only' || $target === $user['dept']) {
        $total_notices++;
    }
}
$unresolved_grievances = 0;
foreach ($db['grievances'] as $g) {
    if ($g['status'] !== 'Resolved' && $g['status'] !== 'Rejected') {
        $unresolved_grievances++;
    }
}
if (isset($db['assignment_grievances'])) {
    foreach ($db['assignment_grievances'] as $g) {
        if ($g['status'] !== 'Resolved' && $g['status'] !== 'Rejected') {
            $unresolved_grievances++;
        }
    }
}
$pending_leaves = 0;
foreach ($db['leaves'] as $l) {
    $l_dept = '';
    foreach ($db['students'] as $s) {
        if ((string)$s['prn'] === (string)$l['student_id'] || (string)$s['username'] === (string)$l['student_id']) {
            $l_dept = $s['department'] ?? $s['dept'] ?? ''; break;
        }
    }
    if (!empty($l_dept) && !match_department($l_dept, $user['dept'])) continue;
    if ($l['status'] === 'Pending') {
        $pending_leaves++;
    }
}
$pending_approvals = $pending_leaves + $unresolved_grievances;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Dashboard - Student Welfare Portal</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="theme-hod">
    <div class="dashboard-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="sidebar-brand">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <div>
                        <span>College ERP</span>
                        <span class="sub">Department Portal</span>
                    </div>
                </div>
                <ul class="sidebar-nav">
                    <li><a class="sidebar-nav-item active" data-tab="dashboard" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-pie"></i><span>Dashboard</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="faculty" onclick="switchTab('faculty')"><i class="fa-solid fa-chalkboard-user"></i><span>Faculty</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="reports" onclick="switchTab('reports')"><i class="fa-solid fa-file-invoice"></i><span>Reports</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="leaves" onclick="switchTab('leaves')"><i class="fa-solid fa-calendar-minus"></i><span>Leaves</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="grievances" onclick="switchTab('grievances')"><i class="fa-solid fa-circle-exclamation"></i><span>Grievances</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="notices" onclick="switchTab('notices')"><i class="fa-solid fa-bullhorn"></i><span>Notices</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="students" onclick="switchTab('students')"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li>
                    <li><a class="sidebar-nav-item" data-tab="approvals" onclick="switchTab('approvals')"><i class="fa-solid fa-check-double"></i><span>Approvals</span> <span class="notification-badge" style="background: var(--primary-color); color: white; padding: 2px 6px; border-radius: 12px; font-size: 0.75rem; margin-left: auto;"><?= $pending_approvals ?></span></a></li>
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
                    <h2 id="currentTabTitle">Overview</h2>
                    <p id="currentTabSubtitle">Welcome back, <?= htmlspecialchars($user['name']) ?></p>
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
                            <span class="name"><?= htmlspecialchars($user['name']) ?></span>
                            <span class="role"><?= htmlspecialchars($user['dept'] ?? 'IT Department') ?></span>
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

            <!-- Dashboard View -->
            <div id="view-dashboard" class="app-view active">
                <h3 style="margin-bottom: 1.5rem; color: var(--text-primary);">Department Summary</h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem;">
                    
                    <!-- Leave Summary Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem;">Pending Leaves</div>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1;"><?= $pending_leaves ?></div>
                            </div>
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: #dcfce7; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                <i class="fa-regular fa-calendar-check"></i>
                            </div>
                        </div>
                        <button onclick="switchTab('leaves')" style="width: 100%; background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 0.6rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 0.85rem;" onmouseover="this.style.background='#f8fafc'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#64748b';">Review Requests <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                    <!-- Grievance Summary Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem;">Open Grievances</div>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1;"><?= $unresolved_grievances ?></div>
                            </div>
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: #ffedd5; color: #f97316; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                <i class="fa-regular fa-comments"></i>
                            </div>
                        </div>
                        <button onclick="switchTab('grievances')" style="width: 100%; background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 0.6rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 0.85rem;" onmouseover="this.style.background='#f8fafc'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#64748b';">View Grievances <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                    <!-- Notice Summary Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem;">Total Notices</div>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1;"><?= $total_notices ?></div>
                            </div>
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: #dbeafe; color: #3b82f6; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                <i class="fa-regular fa-bell"></i>
                            </div>
                        </div>
                        <button onclick="switchTab('notices')" style="width: 100%; background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 0.6rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 0.85rem;" onmouseover="this.style.background='#f8fafc'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#64748b';">Manage Notices <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                    <!-- Students Summary Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem;">Total Students</div>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1;"><?= $total_students ?></div>
                            </div>
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: #ccfbf1; color: #0d9488; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                <i class="fa-solid fa-users"></i>
                            </div>
                        </div>
                        <button onclick="switchTab('students')" style="width: 100%; background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 0.6rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 0.85rem;" onmouseover="this.style.background='#f8fafc'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#64748b';">View Students <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                    <!-- Faculty Summary Card -->
                    <div style="background: var(--bg-card); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem;">Total Faculty</div>
                                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary); line-height: 1;"><?= $total_faculty ?></div>
                            </div>
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                <i class="fa-solid fa-user-tie"></i>
                            </div>
                        </div>
                        <button onclick="switchTab('faculty')" style="width: 100%; background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 0.6rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; justify-content: center; align-items: center; gap: 0.5rem; font-size: 0.85rem;" onmouseover="this.style.background='#f8fafc'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#64748b';">View Faculty <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                </div>

                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Recent Activity</h4>
                    </div>
                    <table class="data-table">
                        <tbody>
                            <?php foreach ($db['recent_activity'] as $activity): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($activity['title']) ?></div>
                                    <div style="font-size:0.85rem;color:var(--text-muted);"><?= htmlspecialchars($activity['desc']) ?></div>
                                </td>
                                <td style="text-align:right;color:var(--text-muted);font-size:0.85rem;">
                                    <?= htmlspecialchars($activity['time']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Leaves View -->
            <div id="view-leaves" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Leave Requests</h4>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Duration</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($db['leaves'] as $l): ?>
                            <tr>
                                <td>
                                    <div class="notice-title"><?= htmlspecialchars($l['applicant_name']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($l['applicant_role']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($l['from']) ?> to <?= htmlspecialchars($l['to']) ?></td>
                                <td><?= htmlspecialchars($l['reason']) ?></td>
                                <td><span class="status-pill <?= strtolower($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span></td>
                                <td class="faculty-actions-cell" style="display:flex; gap:0.5rem; align-items:center;">
                                    <form method="POST" action="delete.php" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="type" value="leaves">
                                        <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn-reject" style="padding: 0.4rem 0.6rem; border-radius:4px;" title="Delete" onclick="return confirm('Delete this leave request?');"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                    <?php if($l['status'] === 'Pending'): ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="approve_leave">
                                        <input type="hidden" name="leave_id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn-approve">Approve</button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="reject_leave">
                                        <input type="hidden" name="leave_id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn-reject">Reject</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="color:var(--text-muted);font-size:0.85rem;">Reviewed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Grievances View -->
            <div id="view-grievances" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Grievances</h4>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Issue</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($db['grievances'] as $g): 
                                if (isset($g['department']) && !match_department($g['department'], $user['dept'])) continue;
                            ?>
                            <tr>
                                <td>
                                    <div class="notice-title"><?= htmlspecialchars($g['student_name']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($g['student_id']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($g['title']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($g['category']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($g['date']) ?></td>
                                <td><span class="status-pill <?= strtolower(str_replace(' ', '-', $g['status'])) ?>"><?= htmlspecialchars($g['status']) ?></span></td>
                                <td style="display:flex; gap:0.5rem; align-items:center;">
                                    <?php if (isset($g['status']) && $g['status'] === 'Resolved'): ?>
                                        <span class="status-pill resolved" style="padding: 0.35rem 0.6rem; border-radius:4px; font-weight:600; font-size: 0.8rem; height: 32px; display: flex; align-items: center;">Resolved</span>
                                    <?php else: ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="resolve_grievance">
                                            <input type="hidden" name="grievance_id" value="<?= $g['id'] ?>">
                                            <button type="submit" class="btn-secondary" style="padding: 0.4rem 0.6rem; border-radius:4px; height: 32px; display: flex; align-items: center;">Mark as Resolved</button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn-secondary" onclick="openGrievanceModal(<?= $g['id'] ?>)" style="padding: 0.4rem 0.6rem; border-radius:4px; height: 32px; display: flex; align-items: center;">View Chat</button>
                                    <form method="POST" action="delete.php" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="type" value="grievances">
                                        <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                        <button type="submit" class="btn-reject" style="padding: 0.4rem 0.6rem; border-radius:4px; height: 32px; display: flex; align-items: center; justify-content: center;" title="Delete" onclick="return confirm('Delete this grievance?');"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Assignment Grievances Section for HOD -->
                <div class="data-table-container" style="margin-top: 2rem;">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Assignment Grievances</h4>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assignment / Subject</th>
                                <th>Issue Type</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_assign_grievances = array_reverse($db['assignment_grievances'] ?? []);
                            if (empty($all_assign_grievances)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; color:var(--text-muted); padding:2rem;">No assignment grievances submitted yet.</td>
                                </tr>
                            <?php else: foreach ($all_assign_grievances as $g): 
                                $sa_item = null;
                                foreach ($db['subject_assignments'] as $sa) {
                                    if ($sa['id'] == $g['subject_assignment_id']) {
                                        $sa_item = $sa;
                                        break;
                                    }
                                }
                                $subject_name = $sa_item ? $sa_item['subject_name'] : 'Unknown Subject';
                                $assign_title = $sa_item ? $sa_item['assignment_title'] : 'Unknown Assignment';
                            ?>
                            <tr>
                                <td>
                                    <div class="notice-title"><?= htmlspecialchars($g['student_name']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($g['student_id']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($subject_name) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($assign_title) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#b91c1c; font-size: 0.85rem;"><?= htmlspecialchars($g['issue_type']) ?></div>
                                    <div style="font-size:0.8rem; color: var(--text-secondary); margin-top:4px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($g['description']) ?>">
                                        <?= htmlspecialchars($g['description']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $st = strtolower($g['status'] ?? 'pending');
                                    $p_bg = '#fee2e2'; $p_col = '#b91c1c';
                                    if ($st === 'resolved') { $p_bg = '#dcfce7'; $p_col = '#15803d'; }
                                    elseif ($st === 'in review') { $p_bg = '#dbeafe'; $p_col = '#1d4ed8'; }
                                    elseif ($st === 'rejected') { $p_bg = '#f3f4f6'; $p_col = '#4b5563'; }
                                    ?>
                                    <span style="background: <?= $p_bg ?>; color: <?= $p_col ?>; padding: 0.3rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">
                                        <?= htmlspecialchars($g['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td style="display:flex; gap:0.5rem; align-items:center;">
                                    <?php if (!empty($g['screenshot'])): ?>
                                        <a href="uploads/<?= htmlspecialchars($g['screenshot']) ?>" target="_blank" class="btn-secondary" style="padding: 0.4rem 0.6rem; border-radius:4px; height: 32px; display: flex; align-items: center; text-decoration: none;">View File</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Notices View -->
            <div id="view-notices" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Notices</h4>
                        <button class="btn-approve" onclick="document.getElementById('modal-publish-notice').classList.add('active')">Publish New</button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Notice</th>
                                <th>Publisher</th>
                                <th>Date / Expiry</th>
                                <th>Attachment</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($db['notices'] as $n): 
                                $target = $n['target_audience'] ?? 'All Departments';
                                if ($target !== 'All Departments' && $target !== 'Faculty Only' && $target !== $user['dept']) {
                                    continue;
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="notice-title"><?= htmlspecialchars($n['title']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($n['desc']) ?></div>
                                </td>
                                <td class="publisher-cell">
                                    <span class="pub-name"><?= htmlspecialchars($n['author']) ?></span>
                                    <span class="pub-role"><?= htmlspecialchars($n['role']) ?></span>
                                </td>
                                <td>
                                    <div class="date-cell"><?= htmlspecialchars($n['date']) ?></div>
                                    <?php if($n['expiry']): ?>
                                    <div class="notice-desc" style="color:#ef4444;">Exp: <?= htmlspecialchars($n['expiry']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($n['attachment']): ?>
                                        <?php $ext = pathinfo($n['attachment'], PATHINFO_EXTENSION); ?>
                                        <a href="<?= htmlspecialchars($n['attachment']) ?>" target="_blank" class="attachment-badge <?= $ext ?>" style="text-decoration:none;">
                                            <i class="fa-solid fa-file-<?= $ext ?>"></i> <?= htmlspecialchars($n['attachment']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="notice-desc">No File</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="delete.php" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="type" value="notices">
                                        <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                        <button type="submit" class="btn-reject" style="padding: 0.4rem 0.6rem; border-radius:4px;" title="Delete" onclick="return confirm('Delete this notice?');"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Students View -->
            <div id="view-students" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Enrolled Students</h4>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>Contact</th>
                                <th>Department</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($db['students'] as $s): ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:1rem;">
                                        <?= get_initials_avatar($s['name'], 40, 16, 0) ?>
                                        <div>
                                            <div class="notice-title"><?= htmlspecialchars($s['name']) ?></div>
                                            <div class="notice-desc"><?= !empty($s['prn']) ? 'PRN: ' . htmlspecialchars($s['prn']) : htmlspecialchars($s['id']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($s['email']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($s['phone']) ?></div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($s['dept']) ?></div>
                                    <div class="notice-desc"><?= htmlspecialchars($s['semester']) ?></div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Faculty View -->
            <div id="view-faculty" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Teaching Faculty</h4>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($db['faculty'] as $f): 
                            // Using mock values for new metrics if not in db
                            $portion = $f['portion_completed'] ?? rand(65, 100);
                            $student_att = $f['student_attendance'] ?? rand(70, 95);
                            $fac_att = rtrim($f['attendance'] ?? '95', '%');
                            
                            $names = explode(' ', $f['name']);
                            $first_initial = strtoupper(substr(str_replace('Prof. ', '', $f['name']), 0, 1));
                            $last_initial = count($names) > 1 ? strtoupper(substr(end($names), 0, 1)) : '';
                            $initials = $first_initial . $last_initial;
                        ?>
                        <div onclick="openFacultyModal('<?= htmlspecialchars(addslashes($f['name'])) ?>', '<?= htmlspecialchars(addslashes($f['designation'])) ?>', '<?= $fac_att ?>', '<?= htmlspecialchars(addslashes($f['workload'] ?? '16 Hours/Wk')) ?>', '<?= $portion ?>', '<?= $student_att ?>', '<?= htmlspecialchars(addslashes($f['subjects'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($f['email'] ?? '')) ?>')" style="background: var(--bg-card); border-radius: 12px; padding: 1rem 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; display: flex; align-items: center; gap: 2rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.borderColor='#8b5cf6'; this.style.boxShadow='0 4px 12px rgba(139, 92, 246, 0.15)';" onmouseout="this.style.borderColor='#f1f5f9'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)';">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #a855f7); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; flex-shrink: 0;">
                                <?= $initials ?>
                            </div>
                            <div style="min-width: 220px;">
                                <h4 style="margin: 0 0 0.25rem 0; color: var(--text-primary); font-size: 1.05rem;"><?= htmlspecialchars($f['name']) ?></h4>
                                <div style="color: var(--text-secondary); font-size: 0.85rem;"><?= htmlspecialchars($f['designation']) ?></div>
                            </div>
                            <div style="flex-grow: 1; padding: 0 1rem; overflow: hidden;">
                                <div style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 500; display: flex; align-items: center; gap: 0.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($f['subjects'] ?? '') ?>">
                                    <i class="fa-solid fa-book-open" style="color: var(--text-muted); flex-shrink: 0;"></i> 
                                    <span style="overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($f['subjects'] ?? '') ?></span>
                                </div>
                            </div>
                            <div style="text-align: right; padding-right: 1rem;">
                                <div style="font-size: 1.1rem; font-weight: 700; color: #8b5cf6;"><?= $portion ?>%</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Portion</div>
                            </div>
                            <i class="fa-solid fa-chevron-right" style="color: #cbd5e1; font-size: 1.2rem;"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Faculty Details Modal -->
                    <div id="facultyModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
                        <div style="background: var(--bg-card); border-radius: 20px; width: 95%; max-width: 750px; overflow: hidden; box-shadow: 0 25px 30px -5px rgba(0,0,0,0.15), 0 15px 15px -5px rgba(0,0,0,0.08); transform: scale(0.95); opacity: 0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);" id="facultyModalContent">
                            <div style="padding: 2.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; background: linear-gradient(to right, #f8fafc, #ffffff);">
                                <div>
                                    <h3 id="modalFacName" style="margin: 0 0 0.5rem 0; color: var(--text-primary); font-size: 1.75rem;">Prof. Name</h3>
                                    <div id="modalFacDesig" style="color: var(--text-secondary); font-size: 1.1rem;">Designation</div>
                                </div>
                                <button onclick="closeFacultyModal()" style="background: transparent; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; padding: 0.25rem; line-height: 1;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div style="padding: 2.5rem; display: flex; flex-direction: column; gap: 2rem;">
                                <!-- Stats Grid -->
                                <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                                    <div style="background: var(--bg-page); padding: 1rem; border-radius: 8px;">
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Workload</div>
                                        <div id="modalFacWorkload" style="font-size: 1.1rem; font-weight: 700; color: var(--text-secondary);">16 Hours/Wk</div>
                                    </div>
                                </div>

                                <!-- Progress Bars -->
                                <div style="display: flex; flex-direction: column; gap: 2rem;">
                                    <div>
                                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.5rem; font-weight: 600;">
                                            <span style="color: var(--text-secondary);">Portion Completed</span>
                                            <span id="modalFacPortion" style="color: #8b5cf6;">68%</span>
                                        </div>
                                        <div style="width: 100%; height: 8px; background: #f3e8ff; border-radius: 4px; overflow: hidden;">
                                            <div id="modalFacPortionBar" style="height: 100%; width: 68%; background: #8b5cf6; border-radius: 4px; transition: width 0.5s ease-out;"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Additional Info -->
                                <div style="border-top: 1px solid #f1f5f9; padding-top: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;">
                                    <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                                        <i class="fa-solid fa-book-open" style="color: var(--text-muted); margin-top: 0.2rem;"></i>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.4;" id="modalFacSubjects">Subjects</div>
                                    </div>
                                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                                        <i class="fa-solid fa-envelope" style="color: var(--text-muted);"></i>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary);" id="modalFacEmail">Email</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                    function openFacultyModal(name, desig, att, workload, portion, studentAtt, subjects, email) {
                        document.getElementById('modalFacName').innerText = name;
                        document.getElementById('modalFacDesig').innerText = desig;
                        
                        const attEl = document.getElementById('modalFacAtt');
                        attEl.innerText = att + '%';
                        attEl.style.color = parseInt(att) >= 90 ? '#10b981' : '#f59e0b';

                        document.getElementById('modalFacWorkload').innerText = workload;
                        
                        document.getElementById('modalFacPortion').innerText = portion + '%';
                        document.getElementById('modalFacPortionBar').style.width = portion + '%';
                        
                        document.getElementById('modalFacStudentAtt').innerText = studentAtt + '%';
                        document.getElementById('modalFacStudentAttBar').style.width = studentAtt + '%';
                        
                        document.getElementById('modalFacSubjects').innerText = subjects;
                        document.getElementById('modalFacEmail').innerText = email;

                        const modal = document.getElementById('facultyModal');
                        const content = document.getElementById('facultyModalContent');
                        modal.style.display = 'flex';
                        // Trigger animation
                        setTimeout(() => {
                            content.style.transform = 'scale(1)';
                            content.style.opacity = '1';
                        }, 10);
                    }

                    function closeFacultyModal() {
                        const modal = document.getElementById('facultyModal');
                        const content = document.getElementById('facultyModalContent');
                        content.style.transform = 'scale(0.95)';
                        content.style.opacity = '0';
                        setTimeout(() => {
                            modal.style.display = 'none';
                        }, 300);
                    }
                    </script>
                </div>
            </div>

            <!-- Reports View -->
            <div id="view-reports" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Reports from Admin</h4>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem;">
                        <?php 
                        $admin_reports = [
                            ['title' => 'Student Marks Report', 'icon' => 'fa-file-excel', 'color' => '#9333ea', 'bg' => '#f3e8ff', 'date' => '20 Jul 2026'],
                            ['title' => 'Assignment Submission Report', 'icon' => 'fa-file-contract', 'color' => '#ea580c', 'bg' => '#ffedd5', 'date' => '18 Jul 2026'],
                            ['title' => 'Leave Report', 'icon' => 'fa-file-excel', 'color' => '#9333ea', 'bg' => '#f3e8ff', 'date' => '17 Jul 2026'],
                            ['title' => 'Grievance Report', 'icon' => 'fa-file-contract', 'color' => '#ea580c', 'bg' => '#ffedd5', 'date' => '15 Jul 2026'],
                            ['title' => 'Notice Report', 'icon' => 'fa-file-pdf', 'color' => '#0284c7', 'bg' => '#e0f2fe', 'date' => '14 Jul 2026'],
                            ['title' => 'Fee Collection Report', 'icon' => 'fa-file-excel', 'color' => '#16a34a', 'bg' => '#dcfce7', 'date' => '12 Jul 2026']
                        ];
                        foreach ($admin_reports as $r):
                        ?>
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                            <div style="display: flex; gap: 2rem; align-items: center;">
                                <div style="width: 48px; height: 48px; border-radius: 12px; background: <?= $r['bg'] ?>; color: <?= $r['color'] ?>; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                                    <i class="fa-solid <?= $r['icon'] ?>"></i>
                                </div>
                                <div>
                                    <h4 style="margin: 0 0 0.4rem 0; font-size: 1.1rem; color: var(--text-primary);"><?= htmlspecialchars($r['title']) ?></h4>
                                    <div style="font-size: 0.85rem; color: var(--text-secondary);">Generated on: <?= $r['date'] ?> by System Admin</div>
                                </div>
                            </div>
                            <button onclick="window.open('download_report.php?report=<?= urlencode(str_replace(' ', '_', $r['title'])) ?>', '_blank')" style="background: var(--bg-page); border: 1px solid var(--border-color); padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; color: var(--text-secondary); font-weight: 600; font-size: 0.9rem; transition: all 0.2s;" onmouseover="this.style.background='var(--bg-alt)';" onmouseout="this.style.background='var(--bg-page)';"><i class="fa-solid fa-download" style="margin-right: 0.5rem;"></i> Download Report</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Approvals View -->
            <div id="view-approvals" class="app-view">
                <div class="data-table-container">
                    <div class="table-header-filters">
                        <h4 style="margin-right:auto;font-weight:700;">Pending Actions</h4>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Requested By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($db['leaves'] as $l): if($l['status'] === 'Pending'): ?>
                            <tr>
                                <td><span class="status-pill pending">Leave</span></td>
                                <td><?= htmlspecialchars($l['reason']) ?> (<?= htmlspecialchars($l['from']) ?> - <?= htmlspecialchars($l['to']) ?>)</td>
                                <td><?= htmlspecialchars($l['applicant_name']) ?></td>
                                <td class="faculty-actions-cell">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="approve_leave">
                                        <input type="hidden" name="leave_id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn-approve">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="reject_leave">
                                        <input type="hidden" name="leave_id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn-reject">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; endforeach; ?>
                            
                            <?php foreach ($db['grievances'] as $g): if($g['status'] === 'Pending'): ?>
                            <tr>
                                <td><span class="status-pill pending">Grievance</span></td>
                                <td><?= htmlspecialchars($g['title']) ?></td>
                                <td><?= htmlspecialchars($g['student_name']) ?></td>
                                <td class="faculty-actions-cell">
                                    <button class="btn-secondary" onclick="openGrievanceModal(<?= $g['id'] ?>)">Resolve</button>
                                </td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Settings View -->
            <div id="view-settings" class="app-view">
                <div class="settings-form-container" style="margin: 0 auto;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_settings">
                        <h3 style="margin-bottom:1.5rem;">Department Settings</h3>
                        <div class="form-row">
                            <div class="form-group-col">
                                <label>Department Name</label>
                                <input type="text" name="dept_name" value="<?= htmlspecialchars($db['settings']['dept_name']) ?>">
                            </div>
                            <div class="form-group-col">
                                <label>Department Code</label>
                                <input type="text" name="dept_code" value="<?= htmlspecialchars($db['settings']['dept_code']) ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group-col">
                                <label>Department Head Name</label>
                                <input type="text" name="hod_name" value="<?= htmlspecialchars($db['settings']['hod_name']) ?>">
                            </div>
                            <div class="form-group-col">
                                <label>Contact Email</label>
                                <input type="email" name="hod_email" value="<?= htmlspecialchars($db['settings']['hod_email']) ?>">
                            </div>
                        </div>
                        <div class="form-row" style="margin-bottom: 2rem;">
                            <div class="form-group-col" style="flex-direction:row;align-items:center;gap:1rem;">
                                <input type="checkbox" id="maintenance" name="maintenance_mode" <?= $db['settings']['maintenance_mode'] ? 'checked' : '' ?> style="width:20px;height:20px;">
                                <label for="maintenance">Maintenance Mode (Hide portal for students)</label>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn-hod-action" style="background:var(--primary-color);color:white;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Publish Notice Modal -->
            <div class="modal-overlay" id="modal-publish-notice" style="transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);">
                <div class="modal-card" style="max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); border: none;">
                    <div class="modal-header" style="background: linear-gradient(to right, #4f46e5, #7c3aed); padding: 1.5rem 2rem; border-bottom: none;">
                        <h3 style="color: white; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.75rem;">
                            <i class="fa-solid fa-bullhorn" style="font-size: 1.25rem;"></i> Publish New Notice
                        </h3>
                        <button class="btn-close-modal" onclick="document.getElementById('modal-publish-notice').classList.remove('active');" style="color: rgba(255,255,255,0.8); background: rgba(255,255,255,0.1); border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'; this.style.color='white';" onmouseout="this.style.background='rgba(255,255,255,0.1)'; this.style.color='rgba(255,255,255,0.8)';"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="background: var(--bg-page);">
                        <input type="hidden" name="action" value="publish_notice">
                        <div class="modal-body" style="padding: 2rem;">
                            
                            <div class="form-group-col" style="margin-bottom: 1.5rem;">
                                <label style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; display: block;"><i class="fa-solid fa-heading" style="color: var(--text-secondary); margin-right: 0.5rem;"></i> Notice Title</label>
                                <input type="text" name="title" required placeholder="e.g. Mid-term Exam Schedule" style="width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.95rem; background: var(--bg-card); transition: all 0.2s; outline: none; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);" onfocus="this.style.borderColor='#7c3aed'; this.style.boxShadow='0 0 0 3px rgba(124,58,237,0.1)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='inset 0 1px 2px rgba(0,0,0,0.02)';">
                            </div>
                            
                            <div class="form-group-col" style="margin-bottom: 1.5rem;">
                                <label style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; display: block;"><i class="fa-solid fa-align-left" style="color: var(--text-secondary); margin-right: 0.5rem;"></i> Detailed Description</label>
                                <textarea name="desc" rows="5" required placeholder="Enter all the necessary details here..." style="width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.95rem; background: var(--bg-card); transition: all 0.2s; outline: none; resize: vertical; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);" onfocus="this.style.borderColor='#7c3aed'; this.style.boxShadow='0 0 0 3px rgba(124,58,237,0.1)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='inset 0 1px 2px rgba(0,0,0,0.02)';"></textarea>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                                <div class="form-group-col">
                                    <label style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; display: block;"><i class="fa-solid fa-calendar-xmark" style="color: var(--text-secondary); margin-right: 0.5rem;"></i> Expiry Date</label>
                                    <input type="date" name="expiry" min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.95rem; background: var(--bg-card); transition: all 0.2s; outline: none;" onfocus="this.style.borderColor='#7c3aed'; this.style.boxShadow='0 0 0 3px rgba(124,58,237,0.1)';" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';">
                                </div>
                                <div class="form-group-col">
                                    <label style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; display: block;"><i class="fa-solid fa-paperclip" style="color: var(--text-secondary); margin-right: 0.5rem;"></i> Attachment <span style="color: var(--text-muted); font-weight: 400; font-size: 0.8rem;">(Optional)</span></label>
                                    <div style="position: relative; overflow: hidden; display: inline-block; width: 100%;">
                                        <button type="button" style="width: 100%; background: var(--bg-card); border: 1px dashed #94a3b8; padding: 0.75rem 1rem; border-radius: 8px; color: var(--text-secondary); font-weight: 500; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem;" onmouseover="this.style.borderColor='#7c3aed'; this.style.color='#7c3aed'; this.style.background='#f3f4f6';" onmouseout="this.style.borderColor='#94a3b8'; this.style.color='#475569'; this.style.background='white';">
                                            <i class="fa-solid fa-cloud-arrow-up"></i> Select File
                                        </button>
                                        <input type="file" name="attachment" style="position: absolute; left: 0; top: 0; opacity: 0; cursor: pointer; height: 100%; width: 100%;">
                                    </div>
                                </div>
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:1rem; margin-top: 1rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                                <button type="button" class="btn-secondary" onclick="document.getElementById('modal-publish-notice').classList.remove('active');" style="padding: 0.6rem 1.5rem; border-radius: 8px; font-weight: 600;">Cancel</button>
                                <button type="submit" class="btn-hod-action" style="background: linear-gradient(to right, #7c3aed, #6d28d9); color: white; padding: 0.6rem 1.5rem; border-radius: 8px; font-weight: 600; border: none; box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.3); transition: all 0.2s; display: flex; align-items: center; gap: 0.5rem;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 8px -1px rgba(124, 58, 237, 0.4)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 6px -1px rgba(124, 58, 237, 0.3)';">
                                    <i class="fa-solid fa-paper-plane"></i> Publish Now
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Grievance Chat Modal -->
            <div class="modal-overlay" id="modal-grievance">
                <div class="modal-card">
                    <div class="modal-header">
                        <h3>Grievance Details</h3>
                        <button class="btn-close-modal" onclick="document.getElementById('modal-grievance').classList.remove('active');"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="modal-body" style="max-height:60vh;overflow-y:auto;">
                        <div style="margin-bottom:1.5rem;">
                            <h4 id="modalGrievanceTitle" style="font-size:1.1rem;font-weight:700;margin-bottom:0.25rem;">Grievance Title</h4>
                            <div id="modalGrievanceAuthorDate" style="font-size:0.85rem;color:var(--text-muted);">Reported by Student on Date</div>
                        </div>
                        
                        <div class="grievance-chat" id="modalChatContainer">
                            <!-- Dynamically loaded -->
                        </div>
                        
                        <div class="chat-reply-box">
                            <label style="font-size:0.9rem;font-weight:600;">Add Reply / Change Status</label>
                            <form method="POST">
                                <input type="hidden" name="action" value="reply_grievance">
                                <input type="hidden" id="modal_grievance_id" name="grievance_id" value="">
                                <textarea name="reply_msg" rows="3" style="width:100%;padding:0.75rem;border:1px solid var(--border-color);border-radius:var(--border-radius-sm);font-family:var(--font-primary);" placeholder="Type your resolution..." required></textarea>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.5rem;">
                                    <select name="status" id="modalGrievanceStatusSelect" style="padding:0.5rem;border:1px solid var(--border-color);border-radius:var(--border-radius-sm);">
                                        <option value="Pending">Mark as Pending</option>
                                        <option value="In Progress">Mark as In Progress</option>
                                        <option value="Resolved">Mark as Resolved</option>
                                    </select>
                                    <button type="submit" class="btn-hod-action" style="background:var(--primary-color);color:white;">Send Reply</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        const grievancesData = <?= json_encode($db['grievances']) ?>;

        const titles = {
            'dashboard': { title: 'Overview', sub: 'Welcome back, <?= addslashes(htmlspecialchars($user["name"])) ?>' },
            'leaves': { title: 'Leave Requests', sub: 'Review and approve leave applications' },
            'grievances': { title: 'Grievances', sub: 'Address and resolve student complaints' },
            'notices': { title: 'Notice Board', sub: 'Publish and manage department announcements' },
            'students': { title: 'Student Directory', sub: 'View student profiles and analytics' },
            'faculty': { title: 'Faculty Directory', sub: 'Manage teaching staff and workload' },
            'reports': { title: 'Reports & Analytics', sub: 'Generate and download department reports' },
            'approvals': { title: 'Pending Approvals', sub: 'Consolidated view of all pending actions' },
            'settings': { title: 'Settings', sub: 'Configure department preferences' }
        };

        function switchTab(tabId) {
            const el = document.querySelector('.sidebar-nav-item[data-tab="' + tabId + '"]');
            
            document.querySelectorAll('.sidebar-nav-item').forEach(nav => nav.classList.remove('active'));
            document.querySelectorAll('.sidebar-nav-item').forEach(nav => nav.style.background = 'transparent');
            document.querySelectorAll('.sidebar-nav-item').forEach(nav => nav.style.color = '#4b5563');
            
            if (el) {
                el.classList.add('active');
                el.style.background = 'var(--primary-light)';
                el.style.color = 'var(--primary-color)';
            }
            
            document.querySelectorAll('.app-view').forEach(view => view.classList.remove('active'));
            document.getElementById('view-' + tabId).classList.add('active');
            
            if(titles[tabId]) {
                document.getElementById('currentTabTitle').textContent = titles[tabId].title;
                document.getElementById('currentTabSubtitle').textContent = titles[tabId].sub;
            }
        }
        
        
        function openGrievanceModal(id) {
            const g = grievancesData.find(item => item.id === id);
            if (!g) return;

            document.getElementById('modal_grievance_id').value = id;
            document.getElementById('modalGrievanceTitle').textContent = g.title;
            document.getElementById('modalGrievanceAuthorDate').textContent = 'Reported by ' + g.student_name + ' (' + g.student_id + ') on ' + g.date;
            document.getElementById('modalGrievanceStatusSelect').value = g.status;

            const chatContainer = document.getElementById('modalChatContainer');
            chatContainer.innerHTML = '';

            // Add original student grievance description as the first bubble
            const studentBubble = document.createElement('div');
            studentBubble.className = 'chat-bubble student-msg';
            studentBubble.innerHTML = `
                <div class="chat-header">
                    <span class="chat-author">${g.student_name}</span>
                </div>
                <div class="chat-message">${escapeHtml(g.desc)}</div>
            `;
            chatContainer.appendChild(studentBubble);

            // Add replies
            if (g.replies && g.replies.length > 0) {
                g.replies.forEach(reply => {
                    const replyBubble = document.createElement('div');
                    replyBubble.className = 'chat-bubble admin-reply';
                    replyBubble.innerHTML = `
                        <div class="chat-header">
                            <span class="chat-author">${reply.author} (${reply.role})</span>
                            <span class="chat-time" style="font-size:0.75rem;margin-left:0.5rem;color:var(--text-muted);">${reply.date}</span>
                        </div>
                        <div class="chat-message">${escapeHtml(reply.message)}</div>
                    `;
                    chatContainer.appendChild(replyBubble);
                });
            }

            document.getElementById('modal-grievance').classList.add('active');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize first tab colors
        document.querySelector('.sidebar-nav-item.active').style.background = 'var(--primary-light)';
        document.querySelector('.sidebar-nav-item.active').style.color = 'var(--primary-color)';
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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
    </script>
</body>
</html>
