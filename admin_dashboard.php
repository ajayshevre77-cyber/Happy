<?php
session_start();
require_once 'db.php';

// Authentication check
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php?role=admin");
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

$active_tab = 'dashboard';
if (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
    unset($_SESSION['active_tab']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_system_config') {
        $db['settings']['maintenance_mode'] = isset($_POST['maintenance_mode']);
        $db['settings']['captcha_enabled'] = isset($_POST['captcha_enabled']);
        $db['settings']['notifications_enabled'] = isset($_POST['notifications_enabled']);
        save_db($db);
        $_SESSION['success_message'] = "System configurations updated successfully.";
        $_SESSION['active_tab'] = 'system-configuration';
        header("Location: admin_dashboard.php");
        exit;
    } elseif ($_POST['action'] === 'update_setting') {
        $key = $_POST['setting_key'] ?? '';
        $val = $_POST['setting_value'] ?? '';
        if ($key && isset($db['settings'][$key])) {
            $db['settings'][$key] = $val;
            save_db($db);
            $_SESSION['success_message'] = "Setting updated successfully!";
        }
        $_SESSION['active_tab'] = 'system-configuration';
        header("Location: admin_dashboard.php");
        exit;
    } elseif ($_POST['action'] === 'add_user') {
        $role = $_POST['role'] ?? '';
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $department = $_POST['department'] ?? 'Information Technology';
        
        $valid_departments = [];
        if (isset($db['departments'])) {
            foreach ($db['departments'] as $d) {
                $valid_departments[] = $d['name'];
            }
        }
        if (!empty($valid_departments) && !in_array($department, $valid_departments)) {
            $_SESSION['error_message'] = "Invalid department selected.";
            $_SESSION['active_tab'] = 'user-management';
            header("Location: admin_dashboard.php");
            exit;
        }
        
        if ($role === 'student') {
            $new_id = '125UIT' . rand(1000, 9999);
            $prn = trim($_POST['prn'] ?? '');
            if (empty($prn)) {
                $prn = generate_next_prn($db, $department);
            }
            $db['students'][] = [
                'id' => $new_id,
                'prn' => $prn,
                'username' => strtolower(str_replace(' ', '', $name)) . rand(10,99),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'dept' => $department,
                'semester' => '1st Semester',
                'attendance' => '100%',
                'status' => 'Active',
                'avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=random'
            ];
            save_db($db);
            $_SESSION['success_message'] = "Student added successfully with PRN: {$prn}!";
            $_SESSION['active_tab'] = 'user-management';
            header("Location: admin_dashboard.php");
            exit;
        } elseif ($role === 'faculty') {
            $designation = $_POST['designation'] ?? 'Assistant Professor';
            $subjects = $_POST['subjects'] ?? 'To be assigned';
            $db['faculty'][] = [
                'id' => 'fac' . rand(100, 999),
                'username' => strtolower(str_replace(' ', '', $name)),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'designation' => $designation,
                'department' => $department,
                'workload' => '0 Hours / Week',
                'attendance' => '100%',
                'subjects' => $subjects,
                'avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=random'
            ];
            save_db($db);
            $_SESSION['success_message'] = "Faculty added successfully!";
            $_SESSION['active_tab'] = 'user-management';
            header("Location: admin_dashboard.php");
            exit;
        }
    } elseif ($_POST['action'] === 'import_students') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['csv_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if ($file_ext !== 'csv') {
                $_SESSION['error_message'] = "Invalid file format. Please upload a .csv file.";
                $_SESSION['active_tab'] = 'user-management';
                header("Location: admin_dashboard.php");
                exit;
            }

            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                // Read header row
                $header = fgetcsv($handle, 1000, ",");
                
                $count = 0;
                $duplicates = 0;
                require_once 'config.php';
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) >= 5) {
                        $student_name = trim($data[0]);
                        $zprn = trim($data[1]);
                        $division = trim($data[2]);
                        $semester = trim($data[3]);
                        $department = trim($data[4]);
                        
                        if (empty($zprn) || empty($student_name)) {
                            continue;
                        }
                        
                        // Check if duplicate ZPRN exists in the database
                        $check = $pdo->prepare("SELECT id FROM students WHERE zprn = ?");
                        $check->execute([$zprn]);
                        if ($check->fetch()) {
                            $duplicates++;
                            continue;
                        }
                        
                        // Insert student with default hashed password
                        $hashed_pass = password_hash($zprn, PASSWORD_BCRYPT);
                        $email = strtolower(explode(' ', $student_name)[0]) . '.' . strtolower(end(explode(' ', $student_name))) . '@erp.edu';
                        $mobile = '+91 9' . rand(10000000, 99999999);
                        
                        $insert = $pdo->prepare("INSERT INTO students (student_name, zprn, password, department, division, semester, email, mobile, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                        $insert->execute([$student_name, $zprn, $hashed_pass, $department, $division, $semester, $email, $mobile]);
                        $count++;
                    }
                }
                fclose($handle);
                $_SESSION['success_message'] = "Import complete! Imported {$count} students successfully." . ($duplicates > 0 ? " ({$duplicates} duplicate ZPRNs skipped)" : "");
            } else {
                $_SESSION['error_message'] = "Failed to open uploaded file.";
            }
        } else {
            $_SESSION['error_message'] = "File upload failed or no file selected.";
        }
        $_SESSION['active_tab'] = 'user-management';
        header("Location: admin_dashboard.php");
        exit;
    } elseif ($_POST['action'] === 'add_department') {
        $name = $_POST['dept_name'] ?? '';
        $code = $_POST['dept_code'] ?? '';
        $intake = $_POST['intake'] ?? 0;
        $hod = $_POST['hod_name'] ?? '';
        
        if (!isset($db['departments'])) {
            $db['departments'] = [];
        }
        
        $db['departments'][] = [
            'id' => 'dept_' . time(),
            'name' => $name,
            'code' => $code,
            'intake' => (int)$intake,
            'hod_name' => $hod
        ];
        save_db($db);
        $_SESSION['success_message'] = "Department added successfully!";
        $_SESSION['active_tab'] = 'department-management';
        header("Location: admin_dashboard.php");
        exit;
    } elseif ($_POST['action'] === 'publish_notice') {
        $title = trim($_POST['title']);
        $desc = trim($_POST['desc']);
        $expiry = trim($_POST['expiry']);
        $target_audience = trim($_POST['target_audience'] ?? 'All Departments');
        
        $valid_audiences = ['All Departments', 'Faculty Only', 'Students Only'];
        if (isset($db['departments'])) {
            foreach ($db['departments'] as $d) {
                $valid_audiences[] = $d['name'];
            }
        }
        if (!in_array($target_audience, $valid_audiences)) {
            $_SESSION['error_message'] = "Invalid target audience selected.";
            $_SESSION['active_tab'] = 'notice-management';
            header("Location: admin_dashboard.php");
            exit;
        }

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
                'author' => $user['name'] ?? 'System Admin',
                'role' => 'Admin',
                'target_audience' => $target_audience,
                'date' => date('d M Y'),
                'expiry' => $expiry,
                'attachment' => $file_name,
                'size' => $file_name ? '1.5MB' : ''
            ];
            $db['recent_activity'] = array_merge([
                [
                    'type' => 'notice',
                    'title' => 'New Notice: ' . $title,
                    'desc' => 'Published for ' . $target_audience,
                    'time' => 'Just now'
                ]
            ], array_slice($db['recent_activity'] ?? [], 0, 9));
            save_db($db);
            $_SESSION['success_message'] = "Notice published successfully.";
            $_SESSION['active_tab'] = 'notice-management';
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $_SESSION['error_message'] = "Title and Description are required.";
            $_SESSION['active_tab'] = 'notice-management';
            header("Location: admin_dashboard.php");
            exit;
        }
    } elseif ($_POST['action'] === 'generate_report') {
        $report_type = $_POST['report_type'] ?? 'Report';
        $format = $_POST['format'] ?? 'pdf';
        // Mock generation behavior
        $_SESSION['success_message'] = "Your " . htmlspecialchars($report_type) . " report is being generated and will download shortly.";
        $_SESSION['active_tab'] = 'report-generation';
        header("Location: admin_dashboard.php");
        exit;
    }
}

// Calculate User Counts
$student_count = count($db['students'] ?? []);

$faculty_count = 0;
$hod_count = 0;
foreach ($db['faculty'] as $f) {
    if (strpos(strtolower($f['designation']), 'hod') !== false) {
        $hod_count++;
    } else {
        $faculty_count++;
    }
}
$admin_count = 1; // Assuming 1 Super Admin

// Calculate Department Statistics
$dept_count = isset($db['departments']) ? count($db['departments']) : 0;
$total_intake = 0;
if (isset($db['departments'])) {
    foreach ($db['departments'] as $d) {
        $total_intake += (int)$d['intake'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College ERP Portal - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #f3f5f9;
            display: flex;
            min-height: 100vh;
            color: #333;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 260px;
            background-color: #1a1b35;
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 10;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            padding: 24px 20px;
            gap: 15px;
        }

        .sidebar-header .shield-icon {
            width: 35px;
            height: 35px;
            background-color: #3f4177;
            border-radius: 8px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .sidebar-header .shield-icon i {
            color: #fff;
            font-size: 16px;
        }

        .sidebar-header-text h2 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .sidebar-header-text p {
            font-size: 11px;
            color: #a0a2c0;
            margin: 2px 0 0 0;
        }

        .nav-links {
            list-style: none;
            padding: 15px 15px;
            flex: 1;
        }

        .nav-item {
            margin-bottom: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #d1d2e8;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            border-radius: 8px;
            transition: background 0.2s, color 0.2s;
            cursor: pointer;
        }

        .nav-link i {
            width: 20px;
            font-size: 15px;
            margin-right: 15px;
            text-align: center;
        }

        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        .nav-link.active {
            background-color: #326bf3;
            color: #fff;
        }

        .logout-container {
            padding: 15px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #e57373;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            background-color: rgba(229, 115, 115, 0.1);
            border-radius: 8px;
            transition: background 0.2s;
        }

        .logout-btn i {
            margin-right: 15px;
        }

        .logout-btn:hover {
            background-color: rgba(229, 115, 115, 0.2);
        }

        /* Main Content Styling */
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 25px 35px;
        }

        .top-banner {
            background-color: var(--bg-card);
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .banner-left h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .banner-left p {
            color: var(--text-secondary);
            font-size: 13.5px;
        }

        .banner-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .notification-icon {
            width: 42px;
            height: 42px;
            background-color: var(--bg-alt);
            color: var(--text-secondary);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 18px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .notification-icon:hover {
            background-color: #e2e8f0;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #8b5cf6;
            padding: 2px;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
        }

        .profile-name {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .profile-role {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .app-view {
            display: none;
        }

        .app-view.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .dashboard-card {
            background-color: var(--bg-card);
            border-radius: 16px;
            padding: 35px 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
        }

        .card-icon-container {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-icon-container i {
            font-size: 24px;
        }

        .dashboard-card h3 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .dashboard-card p {
            color: var(--text-secondary);
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 25px;
            flex: 1;
        }

        .card-btn {
            width: 100%;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            cursor: pointer;
        }

        /* Specific Card Colors */
        .card-purple .card-icon-container { background-color: #f3e8ff; color: #8b5cf6; }
        .card-purple h3 { color: #8b5cf6; }
        .card-purple .card-btn { border: 1px solid #d8b4fe; color: #8b5cf6; }
        .card-purple .card-btn:hover { background-color: #f3e8ff; }

        .card-green .card-icon-container { background-color: #dcfce7; color: #10b981; }
        .card-green h3 { color: #10b981; }
        .card-green .card-btn { border: 1px solid #6ee7b7; color: #10b981; }
        .card-green .card-btn:hover { background-color: #dcfce7; }

        .card-blue .card-icon-container { background: var(--bg-alt); color: #3b82f6; }
        .card-blue h3 { color: #3b82f6; }
        .card-blue .card-btn { border: 1px solid #93c5fd; color: #3b82f6; }
        .card-blue .card-btn:hover { background: var(--bg-alt); }

        .card-orange .card-icon-container { background-color: #ffedd5; color: #f97316; }
        .card-orange h3 { color: #f97316; }
        .card-orange .card-btn { border: 1px solid #fdba74; color: #f97316; }
        .card-orange .card-btn:hover { background-color: #ffedd5; }

        .card-red .card-icon-container { background-color: #ffe4e6; color: #ef4444; }
        .card-red h3 { color: #ef4444; }
        .card-red .card-btn { border: 1px solid #fda4af; color: #ef4444; }
        .card-red .card-btn:hover { background-color: #ffe4e6; }

        /* User Management specific styles */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 20px;
        }

        .stat-info h4 {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 5px;
        }

        .stat-info p {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-students .stat-icon { background: #eff6ff; color: #3b82f6; }
        .stat-faculty .stat-icon { background: #f0fdf4; color: #22c55e; }
        .stat-hod .stat-icon { background: #fdf2f8; color: #ec4899; }
        .stat-admin .stat-icon { background: #f5f3ff; color: #8b5cf6; }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .section-header h2 {
            font-size: 18px;
            color: var(--text-primary);
        }
        .add-btn {
            background-color: #3b82f6;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .add-btn:hover { background-color: #2563eb; }

        .table-container {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background-color: var(--bg-page);
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-secondary);
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        td {
            font-size: 14px;
            color: var(--text-primary);
        }
        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-cell img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-student { background: #eff6ff; color: #3b82f6; }
        .badge-faculty { background: #f0fdf4; color: #22c55e; }
        .badge-hod { background: #fdf2f8; color: #ec4899; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal.active {
            display: flex;
            opacity: 1;
        }
        .modal-content {
            background: var(--bg-card);
            width: 100%;
            max-width: 500px;
            border-radius: 16px;
            padding: 30px;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        .modal.active .modal-content {
            transform: translateY(0);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .modal-header h3 {
            font-size: 18px;
            color: var(--text-primary);
        }
        .close-modal {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-secondary);
            cursor: pointer;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 8px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #3b82f6;
        }
        .submit-btn {
            width: 100%;
            padding: 12px;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }
        .submit-btn:hover { background: #2563eb; }
        
        /* Toast Notifications */
        .toast-notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 16px 24px;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 600;
            z-index: 9999;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: toastSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards, toastFadeOut 0.4s ease 3s forwards;
        }

        .toast-success {
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .toast-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @keyframes toastSlideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes toastFadeOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(150%); opacity: 0; }
        }
        
        /* Profile Modal CSS */
        .user-name-link {
            color: #3b82f6;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .user-name-link:hover {
            color: #2563eb;
            text-decoration: underline;
        }
        
        .profile-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1050;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .profile-modal-overlay.active {
            display: flex;
            opacity: 1;
        }
        .profile-card {
            background: rgba(255, 255, 255, 0.95);
            width: 100%;
            max-width: 400px;
            border-radius: 24px;
            overflow: hidden;
            transform: translateY(20px) scale(0.95);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        .profile-modal-overlay.active .profile-card {
            transform: translateY(0) scale(1);
        }
        .profile-card-header {
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            padding: 40px 20px 20px;
            text-align: center;
            position: relative;
            color: white;
        }
        .close-profile-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            width: 32px; height: 32px;
            border-radius: 50%;
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex; justify-content: center; align-items: center;
            transition: background 0.2s;
        }
        .close-profile-btn:hover { background: rgba(255, 255, 255, 0.4); }
        .profile-avatar-wrapper {
            width: 100px; height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            margin: 0 auto 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        #pm-name {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }
        .pm-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .profile-card-body {
            padding: 30px;
            background: var(--bg-card);
        }
        .pm-info-row {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .pm-info-row:last-child {
            margin-bottom: 0; padding-bottom: 0; border-bottom: none;
        }
        .pm-info-icon {
            width: 40px; height: 40px;
            background: var(--bg-page);
            border-radius: 12px;
            display: flex; justify-content: center; align-items: center;
            color: #6366f1;
            font-size: 18px;
            flex-shrink: 0;
            transition: transform 0.2s, background 0.2s;
        }
        .pm-info-row:hover .pm-info-icon {
            transform: scale(1.1);
            background: #eff6ff;
        }
        .pm-info-text label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 3px;
            letter-spacing: 0.5px;
        }
        .pm-info-text p {
            font-size: 14.5px;
            color: var(--text-primary);
            font-weight: 600;
            margin: 0;
            line-height: 1.4;
        }

    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="shield-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div class="sidebar-header-text">
                <h2>College ERP</h2>
                <p>Admin Panel</p>
            </div>
        </div>

        <ul class="nav-links">
            <li class="nav-item">
                <a onclick="switchTab('dashboard')" class="nav-link active" id="nav-dashboard">
                    <i class="fa-solid fa-border-all"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('user-management')" class="nav-link" id="nav-user-management">
                    <i class="fa-solid fa-user-group"></i>
                    User Management
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('department-management')" class="nav-link" id="nav-department-management">
                    <i class="fa-solid fa-building"></i>
                    Department Management
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('notice-management')" class="nav-link" id="nav-notice-management">
                    <i class="fa-solid fa-bullhorn"></i>
                    Notice Management
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('report-generation')" class="nav-link" id="nav-report-generation">
                    <i class="fa-solid fa-file-contract"></i>
                    Report Generation
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('grievance-management')" class="nav-link" id="nav-grievance-management">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    Grievance Management
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('system-configuration')" class="nav-link" id="nav-system-configuration">
                    <i class="fa-solid fa-gear"></i>
                    System Configuration
                </a>
            </li>
            <li class="nav-item">
                <a onclick="switchTab('student-marks')" class="nav-link" id="nav-student-marks">
                    <i class="fa-solid fa-graduation-cap"></i>
                    Student Marks
                </a>
            </li>
        </ul>

        <div class="logout-container">
            <a href="logout.php" class="logout-btn">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="top-banner">
            <div class="banner-left">
                <h1 id="banner-title">Dashboard</h1>
                <p id="banner-desc">Welcome to the Admin Panel.</p>
            </div>
            <div class="banner-right" style="display: flex; align-items: center; gap: 1rem;">
                <button class="theme-toggle-btn" title="Toggle Dark/Light Theme" onclick="toggleDarkMode()">
                    <i class="fa-solid fa-moon"></i>
                </button>
                <div class="notification-wrapper" style="position: relative;">
                    <div class="notification-icon" id="notificationToggle" style="cursor:pointer;">
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
                <div class="user-profile">
                    <!-- Removed admin profile avatar per user request -->
                    <div class="profile-info">
                        <span class="profile-name">Admin User</span>
                        <span class="profile-role">System Administrator</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if(!empty($success_message)): ?>
        <div class="toast-notification toast-success">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>
        <?php if(!empty($error_message)): ?>
        <div class="toast-notification toast-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <!-- Dashboard View -->
        <div id="view-dashboard" class="app-view active">
            <!-- Admin Portal Summary -->

            <h3 style="font-size: 1.15rem; color: var(--text-primary); margin-bottom: 20px; font-weight: 700;">Quick Access</h3>
            <div class="cards-grid">
                <!-- User Management Card -->
                <div class="dashboard-card card-purple">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-user-group"></i>
                    </div>
                    <h3>User Management</h3>
                    <p>Manage users, roles and permissions.</p>
                    <a onclick="switchTab('user-management')" class="card-btn">
                        <span>Go to User Management</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <!-- Department Management Card -->
                <div class="dashboard-card card-green">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-table-cells"></i>
                    </div>
                    <h3>Department Management</h3>
                    <p>Manage departments and related details.</p>
                    <a onclick="switchTab('department-management')" class="card-btn">
                        <span>Go to Department Management</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <!-- Notice Management Card -->
                <div class="dashboard-card card-blue">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <h3>Notice Management</h3>
                    <p>Create, update and manage notices.</p>
                    <a onclick="switchTab('notice-management')" class="card-btn">
                        <span>Go to Notice Management</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <!-- Report Generation Card -->
                <div class="dashboard-card card-orange">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <h3>Report Generation</h3>
                    <p>Generate and view various reports.</p>
                    <a onclick="switchTab('report-generation')" class="card-btn">
                        <span>Go to Report Generation</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <!-- System Configuration Card -->
                <div class="dashboard-card card-red">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-gear"></i>
                    </div>
                    <h3>System Configuration</h3>
                    <p>Configure system settings and preferences.</p>
                    <a onclick="switchTab('system-configuration')" class="card-btn">
                        <span>Go to System Configuration</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <!-- Student Marks Card -->
                <div class="dashboard-card card-blue">
                    <div class="card-icon-container">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <h3>Student Marks</h3>
                    <p>View and manage student marks.</p>
                    <a onclick="switchTab('student-marks')" class="card-btn">
                        <span>Go to Student Marks</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- User Management View -->
        <div id="view-user-management" class="app-view">
            <div class="stats-row">
                <div class="stat-card stat-students">
                    <div class="stat-icon"><i class="fa-solid fa-user-graduate"></i></div>
                    <div class="stat-info">
                        <h4>Total Students</h4>
                        <p><?php echo $student_count; ?></p>
                    </div>
                </div>
                <div class="stat-card stat-faculty">
                    <div class="stat-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                    <div class="stat-info">
                        <h4>Total Faculties</h4>
                        <p><?php echo $faculty_count; ?></p>
                    </div>
                </div>
                <div class="stat-card stat-hod">
                    <div class="stat-icon"><i class="fa-solid fa-user-tie"></i></div>
                    <div class="stat-info">
                        <h4>Total HOD</h4>
                        <p><?php echo $hod_count; ?></p>
                    </div>
                </div>
                <div class="stat-card stat-admin">
                    <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="stat-info">
                        <h4>Total Admin</h4>
                        <p><?php echo $admin_count; ?></p>
                    </div>
                </div>
            </div>

            <div class="section-header">
                <h2>All Users</h2>
                <div style="display: flex; gap: 10px;">
                    <button class="add-btn" onclick="openModal()"><i class="fa-solid fa-plus"></i> Add User</button>
                    <button class="add-btn" onclick="openImportModal()" style="background-color: #10b981;"><i class="fa-solid fa-file-import"></i> Import CSV</button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>PRN / ID</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Subjects</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $get_shortform = function($dept) {
                            $map = [
                                'Information Technology' => 'IT',
                                'Computer Engineering' => 'CE',
                                'Computer Science' => 'CS',
                                'Electronics & Telecommunication' => 'EXTC',
                                'Mechanical Engineering' => 'ME',
                                'Civil Engineering' => 'CE'
                            ];
                            return isset($map[$dept]) ? $map[$dept] : str_replace('Information Technology', 'IT', $dept);
                        };

                        // Display HOD first, then faculty, then students
                        foreach ($db['faculty'] as $f) {
                            $isHod = strpos(strtolower($f['designation']), 'hod') !== false;
                            $badgeClass = $isHod ? 'badge-hod' : 'badge-faculty';
                            $roleLabel = $isHod ? 'HOD' : 'Faculty';
                            $department = isset($f['department']) ? $f['department'] : 'Information Technology';
                            $department = $get_shortform($department);
                            $subjects = isset($f['subjects']) ? $f['subjects'] : 'N/A';
                            
                            $user_data = htmlspecialchars(json_encode([
                                'name' => $f['name'],
                                'role' => $roleLabel,
                                'department' => $department,
                                'subjects' => $subjects,
                                'email' => $f['email'],
                                'phone' => $f['phone'],
                                'avatar' => isset($f['avatar']) && !empty($f['avatar']) ? $f['avatar'] : 'https://ui-avatars.com/api/?name='.urlencode($f['name']).'&background=random'
                            ]), ENT_QUOTES, 'UTF-8');
                            
                            echo "<tr>";
                            echo "<td><div class='user-cell'><span class='user-name-link' onclick='showUserProfile(this)' data-user='{$user_data}'>".htmlspecialchars($f['name'])."</span></div></td>";
                            echo "<td><span style='font-size:0.85rem; color: var(--text-secondary); font-weight:600;'>".htmlspecialchars($f['id'])."</span></td>";
                            echo "<td><span class='badge {$badgeClass}'>{$roleLabel}</span></td>";
                            echo "<td>".htmlspecialchars($department)."</td>";
                            echo "<td>".htmlspecialchars($subjects)."</td>";
                            echo "<td>".htmlspecialchars($f['email'])."</td>";
                            echo "<td>".htmlspecialchars($f['phone'])."</td>";
                            echo "<td><span style='color: #22c55e; font-weight: 500;'><i class='fa-solid fa-circle' style='font-size: 8px; margin-right: 4px;'></i> Active</span></td>";
                            echo "<td>";
                            if (!$isHod) {
                                echo "<form method='POST' action='delete.php' style='display:inline;' onsubmit='return confirm(\"Are you sure you want to delete this faculty member?\");'>
                                        <input type='hidden' name='action' value='delete_item'>
                                        <input type='hidden' name='type' value='faculty'>
                                        <input type='hidden' name='id' value='".htmlspecialchars($f['id'])."'>
                                        <button type='submit' style='background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px;' title='Delete Faculty'>
                                            <i class='fa-solid fa-trash'></i>
                                        </button>
                                      </form>";
                            }
                            echo "</td>";
                            echo "</tr>";
                        }
                        
                        foreach ($db['students'] as $s) {
                            $student_dept = explode(' - ', $s['dept'] ?? 'Information Technology')[0];
                            $student_dept = $get_shortform($student_dept);
                            $display_prn = !empty($s['prn']) ? $s['prn'] : $s['id'];
                            
                            $user_data = htmlspecialchars(json_encode([
                                'name' => $s['name'],
                                'role' => 'Student',
                                'prn' => $display_prn,
                                'department' => $student_dept,
                                'subjects' => 'N/A',
                                'email' => $s['email'],
                                'email' => $s['email'] ?? 'N/A',
                                'phone' => $s['mobile'] ?? 'N/A',
                                'avatar' => $avatar
                            ]), ENT_QUOTES, 'UTF-8');
                            
                            echo "<tr>";
                            echo "<td><div class='user-cell'><img src='".htmlspecialchars($avatar)."' alt='Avatar' class='user-avatar'><span class='user-name-link' onclick='showUserProfile(this)' data-user='{$user_data}'>".htmlspecialchars($s['name'])."</span></div></td>";
                            echo "<td><span style='font-size:0.85rem; color: var(--text-secondary); font-weight:600;'>".htmlspecialchars($display_prn)."</span></td>";
                            echo "<td><span class='badge badge-student'>Student</span></td>";
                            echo "<td>".htmlspecialchars($student_dept)."</td>";
                            echo "<td>-</td>";
                            echo "<td>".htmlspecialchars($s['email'] ?? 'N/A')."</td>";
                            echo "<td>".htmlspecialchars($s['mobile'] ?? 'N/A')."</td>";
                            echo "<td><span style='color: #22c55e; font-weight: 500;'><i class='fa-solid fa-circle' style='font-size: 8px; margin-right: 4px;'></i> {$statusLabel}</span></td>";
                            echo "<td></td>"; // Empty action column for students
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Department Management View -->
        <div id="view-department-management" class="app-view">
            <div class="stats-row">
                <div class="stat-card stat-student">
                    <div class="stat-icon"><i class="fa-solid fa-building"></i></div>
                    <div class="stat-info">
                        <h4>Total Departments</h4>
                        <p><?php echo $dept_count; ?></p>
                    </div>
                </div>
                <div class="stat-card stat-faculty">
                    <div class="stat-icon"><i class="fa-solid fa-users-line"></i></div>
                    <div class="stat-info">
                        <h4>Total Intake Capacity</h4>
                        <p><?php echo $total_intake; ?></p>
                    </div>
                </div>
            </div>

            <div class="section-header">
                <h2>All Departments</h2>
                <button class="add-btn" onclick="openDeptModal()"><i class="fa-solid fa-plus"></i> Add Department</button>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Department Name</th>
                            <th>Code</th>
                            <th>Intake Capacity</th>
                            <th>HOD Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (isset($db['departments'])) {
                            foreach ($db['departments'] as $d) {
                                echo "<tr>";
                                echo "<td><strong>".htmlspecialchars($d['name'])."</strong></td>";
                                echo "<td><span class='badge badge-faculty'>".htmlspecialchars($d['code'])."</span></td>";
                                echo "<td>".htmlspecialchars($d['intake'])."</td>";
                                echo "<td>".htmlspecialchars($d['hod_name'])."</td>";
                                echo "<td><span style='color: #22c55e; font-weight: 500;'><i class='fa-solid fa-circle' style='font-size: 8px; margin-right: 4px;'></i> Active</span></td>";
                                echo "</tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notice Management View -->
        <div id="view-notice-management" class="app-view">
            <div style="text-align: center; margin-bottom: 2rem;">
                <h2 style="font-size: 2.25rem; color: #3b82f6; font-weight: 800; margin-bottom: 0.5rem;">Publish Notice</h2>
                <p style="color: var(--text-secondary);">Post announcements and broadcast updates to everyone or specific departments.</p>
            </div>
            
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 3rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="publish_notice">
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Notice Title</label>
                        <input type="text" name="title" required placeholder="e.g. Extra Class Scheduled" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem;">
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Target Audience (Department)</label>
                        <select name="target_audience" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem; outline: none;">
                            <option value="All Departments">All Departments</option>
                            <?php foreach($db['departments'] ?? [] as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="Faculty Only">Faculty Only</option>
                            <option value="Students Only">Students Only</option>
                        </select>
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
                                <p style="font-size: 0.9rem; color: var(--text-secondary);">Click here to <label for="admin-notice-upload" style="color: #3b82f6; font-weight: 600; cursor: pointer;">browse</label> and select a file</p>
                                <input id="admin-notice-upload" type="file" name="attachment" style="display: none;">
                                <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">Supported formats: PDF, DOCX, JPG, PNG (Max 5MB)</p>
                            </div>
                        </div>
                        <label for="admin-notice-upload" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.65rem 1.25rem; border-radius: 6px; color: #3b82f6; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background 0.2s;">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Choose File
                        </label>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" style="background: #3b82f6; color: white; border: none; padding: 0.85rem 1.75rem; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 1rem; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2); transition: transform 0.2s, box-shadow 0.2s;">Publish Notice</button>
                    </div>
                </form>
            </div>
            
            <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--text-primary);">Published Notices</h3>
            
            <?php foreach (array_reverse($db['notices'] ?? []) as $n): ?>
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden;">
                <div style="padding: 1.5rem; display: flex; gap: 2rem; align-items: flex-start;">
                    <div style="width: 48px; height: 48px; background: var(--bg-card);1f2; color: #e11d48; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <h4 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($n['title']) ?></h4>
                            <?php if (isset($n['target_audience'])): ?>
                                <span style="background: var(--bg-alt); color: var(--text-secondary); padding: 0.25rem 0.65rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($n['target_audience']) ?></span>
                            <?php endif; ?>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 0.65rem;"><?= htmlspecialchars($n['desc']) ?></p>
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

        <!-- Report Generation View -->
        <div id="view-report-generation" class="app-view">
            
            <div style="display: flex; justify-content: flex-end; margin-bottom: 1.5rem;">
                <button onclick="document.getElementById('reportFormContainer').style.display = document.getElementById('reportFormContainer').style.display === 'none' ? 'block' : 'none';" style="background: #5b21b6; color: white; border: none; padding: 0.6rem 1.25rem; border-radius: 6px; font-weight: 500; cursor: pointer; font-family: inherit; font-size: 0.95rem; box-shadow: 0 2px 4px rgba(91, 33, 182, 0.2); transition: transform 0.2s, box-shadow 0.2s; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-regular fa-file-lines"></i> Generate Report
                </button>
            </div>

            <!-- Form Container (Hidden by default) -->
            <div id="reportFormContainer" style="display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                <form method="POST" action="admin_dashboard.php">
                    <input type="hidden" name="action" value="generate_report">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Report Type</label>
                            <select name="report_type" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem; outline: none;">
                                <option value="" disabled selected>Select Report Type</option>
                                <option value="Student Master List">Student Master List</option>
                                <option value="Faculty Directory">Faculty Directory</option>
                                <option value="Overall Attendance">Overall Attendance</option>
                                <option value="Leave Applications">Leave Applications</option>
                                <option value="Notice History">Notice History</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Department Filter</label>
                            <select name="department" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem; outline: none;">
                                <option value="All">All Departments</option>
                                <?php if(isset($db['departments'])): foreach($db['departments'] as $d): ?>
                                    <option value="<?= htmlspecialchars($d['name']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1.5rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Start Date (Optional)</label>
                            <input type="date" name="start_date" min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">End Date (Optional)</label>
                            <input type="date" name="end_date" min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 1rem;">
                        </div>
                    </div>
                    <div style="margin-bottom: 2rem;">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">Export Format</label>
                        <div style="display: flex; gap: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; transition: border 0.2s;">
                                <input type="radio" name="format" value="pdf" checked style="accent-color: #5b21b6;">
                                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> PDF Document
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; transition: border 0.2s;">
                                <input type="radio" name="format" value="csv" style="accent-color: #5b21b6;">
                                <i class="fa-solid fa-file-csv" style="color: #10b981;"></i> CSV/Excel
                            </label>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; padding-top: 1.5rem; border-top: 1px solid #f1f5f9;">
                        <button type="submit" style="background: #5b21b6; color: white; border: none; padding: 0.85rem 1.75rem; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 1rem; box-shadow: 0 4px 6px rgba(91, 33, 182, 0.2); transition: transform 0.2s, box-shadow 0.2s; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-download"></i> Generate & Download
                        </button>
                    </div>
                </form>
            </div>

            <!-- Stats Cards -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 2rem; margin-bottom: 2rem;">
                <!-- Card 1 -->
                <div style="background: var(--bg-card); border-radius: 8px; border: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                    <div style="background: #f3e8ff; color: #8b5cf6; width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0;">
                        <i class="fa-regular fa-file-lines"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-primary); font-weight: 600; margin-bottom: 0.25rem;">Total Reports</div>
                        <div style="font-size: 1.6rem; font-weight: 700; color: #8b5cf6; line-height: 1;">32</div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">All time</div>
                    </div>
                </div>
                <!-- Card 2 -->
                <div style="background: var(--bg-card); border-radius: 8px; border: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                    <div style="background: #dcfce7; color: #22c55e; width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0;">
                        <i class="fa-solid fa-user-group"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-primary); font-weight: 600; margin-bottom: 0.25rem;">Student Reports</div>
                        <div style="font-size: 1.6rem; font-weight: 700; color: #22c55e; line-height: 1;">16</div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">All time</div>
                    </div>
                </div>
                <!-- Card 3 -->
                <div style="background: var(--bg-card); border-radius: 8px; border: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                    <div style="background: #ffedd5; color: #f97316; width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0;">
                        <i class="fa-regular fa-user"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-primary); font-weight: 600; margin-bottom: 0.25rem;">Faculty Reports</div>
                        <div style="font-size: 1.6rem; font-weight: 700; color: #f97316; line-height: 1;">8</div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">All time</div>
                    </div>
                </div>
                <!-- Card 4 -->
                <div style="background: var(--bg-card); border-radius: 8px; border: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.01);">
                    <div style="background: var(--bg-alt); color: #3b82f6; width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0;">
                        <i class="fa-regular fa-calendar"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-primary); font-weight: 600; margin-bottom: 0.25rem;">Generated This Month</div>
                        <div style="font-size: 1.6rem; font-weight: 700; color: #3b82f6; line-height: 1;">7</div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">May 2024</div>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div style="background: var(--bg-card); border-radius: 10px; border: 1px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.01); padding: 1.5rem;">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem;">Reports List</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); background: #fafaf9;">
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Report Name</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Category</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Generated By</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Date</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Status</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem; text-align: center;">Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Student Attendance Report</td>
                                <td style="padding: 1rem;"><span style="background: #f3e8ff; color: #8b5cf6; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Student</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">20 May 2024 10:30 AM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Student_Attendance_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Student Marks Report</td>
                                <td style="padding: 1rem;"><span style="background: #f3e8ff; color: #8b5cf6; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Student</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">19 May 2024 04:15 PM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Student_Marks_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Faculty Attendance Report</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Faculty</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">18 May 2024 11:20 AM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Faculty_Attendance_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Assignment Submission Report</td>
                                <td style="padding: 1rem;"><span style="background: #ffedd5; color: #ea580c; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Academic</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">17 May 2024 02:45 PM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Assignment_Submission_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Leave Report</td>
                                <td style="padding: 1rem;"><span style="background: #dbeafe; color: #2563eb; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Leave</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">16 May 2024 09:10 AM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Leave_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Grievance Report</td>
                                <td style="padding: 1rem;"><span style="background: #fee2e2; color: #e11d48; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Grievance</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">15 May 2024 03:30 PM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Grievance_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Notice Report</td>
                                <td style="padding: 1rem;"><span style="background: #cffafe; color: #0891b2; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Notice</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">14 May 2024 10:05 AM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Notice_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                            <tr>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;">Fee Collection Report</td>
                                <td style="padding: 1rem;"><span style="background: #fef3c7; color: #d97706; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Finance</span></td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">Administrator</td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);">13 May 2024 05:00 PM</td>
                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #16a34a; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">Completed</span></td>
                                <td style="padding: 1rem; text-align: center;"><a href="download_report.php?report=Fee_Collection_Report" target="_blank" style="color: #8b5cf6; font-size: 1.1rem; text-decoration: none;"><i class="fa-solid fa-download"></i></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </div>

        <!-- Grievance Management View -->
        <div id="view-grievance-management" class="app-view">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Grievance Management</h2>
                    <p style="color: var(--text-secondary); font-size: 0.95rem;">Monitor system-wide general and assignment grievances.</p>
                </div>
            </div>

            <!-- General Grievances Section -->
            <div style="background: var(--bg-card); border-radius: 10px; border: 1px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.01); padding: 1.5rem; margin-bottom: 2rem;">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem;">General Grievances</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); background: #fafaf9;">
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Student</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Category</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Title & Issue</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Date</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_grievances = array_reverse($db['grievances'] ?? []);
                            if (empty($all_grievances)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; color:var(--text-muted); padding:2rem;">No general grievances submitted yet.</td>
                                </tr>
                            <?php else: foreach ($all_grievances as $g): ?>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 500; font-size: 0.9rem; color: var(--text-primary);"><?= htmlspecialchars($g['student_name'] ?? 'Unknown') ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($g['student_id'] ?? '') ?></div>
                                </td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($g['category'] ?? '') ?></td>
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($g['title'] ?? '') ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top:4px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($g['desc'] ?? '') ?>"><?= htmlspecialchars($g['desc'] ?? '') ?></div>
                                </td>
                                <td style="padding: 1rem; font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($g['date'] ?? '') ?></td>
                                <td style="padding: 1rem;">
                                    <?php 
                                    $st = strtolower($g['status'] ?? 'pending');
                                    $p_bg = '#fee2e2'; $p_col = '#b91c1c';
                                    if ($st === 'resolved') { $p_bg = '#dcfce7'; $p_col = '#15803d'; }
                                    elseif ($st === 'in review') { $p_bg = '#dbeafe'; $p_col = '#1d4ed8'; }
                                    elseif ($st === 'rejected') { $p_bg = '#f3f4f6'; $p_col = '#4b5563'; }
                                    ?>
                                    <span style="background: <?= $p_bg ?>; color: <?= $p_col ?>; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($g['status'] ?? 'Pending') ?></span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Assignment Grievances Section -->
            <div style="background: var(--bg-card); border-radius: 10px; border: 1px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.01); padding: 1.5rem;">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem;">Assignment Grievances</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); background: #fafaf9;">
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Student</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Assignment / Subject</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Issue Type</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Status</th>
                                <th style="padding: 1rem; font-weight: 600; color: var(--text-primary); font-size: 0.85rem;">Action</th>
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
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 500; font-size: 0.9rem; color: var(--text-primary);"><?= htmlspecialchars($g['student_name']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($g['student_id']) ?></div>
                                </td>
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 500; font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($subject_name) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($assign_title) ?></div>
                                </td>
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 600; color: #b91c1c; font-size: 0.85rem;"><?= htmlspecialchars($g['issue_type']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top:4px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($g['description']) ?>">
                                        <?= htmlspecialchars($g['description']) ?>
                                    </div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php 
                                    $st = strtolower($g['status'] ?? 'pending');
                                    $p_bg = '#fee2e2'; $p_col = '#b91c1c';
                                    if ($st === 'resolved') { $p_bg = '#dcfce7'; $p_col = '#15803d'; }
                                    elseif ($st === 'in review') { $p_bg = '#dbeafe'; $p_col = '#1d4ed8'; }
                                    elseif ($st === 'rejected') { $p_bg = '#f3f4f6'; $p_col = '#4b5563'; }
                                    ?>
                                    <span style="background: <?= $p_bg ?>; color: <?= $p_col ?>; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                                        <?= htmlspecialchars($g['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td style="padding: 1rem; display: flex; gap: 0.5rem; align-items: center;">
                                    <?php if (!empty($g['screenshot'])): ?>
                                        <a href="uploads/<?= htmlspecialchars($g['screenshot']) ?>" target="_blank" style="background: #3b82f6; color: white; padding: 0.4rem 0.6rem; border-radius:4px; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; text-decoration: none;">View File</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- ============================================ -->
        <!-- 6. STUDENT MARKS VIEW                        -->
        <!-- ============================================ -->
                <div id="view-student-marks" class="app-view">
            <style>
                .overview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
                .overview-filters { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; background: var(--bg-card); padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 2rem; position: sticky; top: 0; z-index: 10; }
                .overview-filters select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-color); color: var(--text-color); font-size: 0.9rem; outline: none; }
                .search-box { flex-grow: 1; min-width: 200px; display: flex; align-items: center; border: 1px solid var(--border-color); border-radius: 6px; padding: 0.4rem 0.8rem; background: var(--bg-page); }
                .search-box input { border: none; background: transparent; outline: none; margin-left: 0.5rem; width: 100%; font-size: 0.9rem; }
                .export-btn { background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 6px; color: var(--text-color); font-weight: 500; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
                .export-btn:hover { background: var(--bg-alt); }
                
                .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
                .stat-card { background: var(--bg-card); border-radius: 12px; padding: 1.2rem; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; cursor: default; }
                .stat-card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }
                .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
                .stat-icon.blue { background: #eff6ff; color: #3b82f6; }
                .stat-icon.green { background: #f0fdf4; color: #22c55e; }
                .stat-icon.purple { background: #faf5ff; color: #a855f7; }
                .stat-icon.orange { background: #fef3c7; color: #f59e0b; }
                .stat-icon.red { background: #fef2f2; color: #ef4444; }
                .stat-info h3 { font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; margin: 0 0 0.25rem 0; letter-spacing: 0.5px; }
                .stat-info .value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: baseline; gap: 0.5rem; }
                .stat-info .sub-value { font-size: 0.85rem; font-weight: 500; color: var(--text-muted); }
                
                .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem; }
                @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
                
                .panel { background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; display: flex; flex-direction: column; }
                .panel-header { padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border-color); background: var(--bg-page); }
                .panel-title { font-weight: 700; color: var(--text-primary); font-size: 1.1rem; margin: 0 0 0.25rem 0; }
                .panel-subtitle { font-size: 0.85rem; color: var(--text-secondary); margin: 0; }
                .panel-body { padding: 0; flex-grow: 1; overflow-y: auto; }
                .panel-inner { padding: 1.5rem; }
                
                /* Subject Table */
                .subject-row { border-bottom: 1px solid var(--border-color); transition: background 0.2s; cursor: pointer; display: flex; align-items: center; padding: 1rem 1.5rem; gap: 1rem; }
                .subject-row:hover { background: var(--bg-page); }
                .subject-name-col { flex: 2; min-width: 200px; }
                .subject-name { font-weight: 600; color: var(--text-primary); font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.2rem; }
                .subject-faculty { font-size: 0.8rem; color: var(--text-secondary); }
                .subject-stats-col { flex: 1; text-align: center; }
                .subject-stat-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.2rem; }
                .subject-stat-val { font-weight: 600; color: var(--text-primary); font-size: 0.95rem; }
                
                /* Progress circle */
                .circular-chart { display: block; margin: 0 auto; max-width: 80%; max-height: 250px; }
                .circle-bg { fill: none; stroke: #f1f5f9; stroke-width: 3.8; }
                .circle { fill: none; stroke-width: 2.8; stroke-linecap: round; animation: progress 1s ease-out forwards; }
                @keyframes progress { 0% { stroke-dasharray: 0 100; } }
                .percentage { fill: #1e293b; font-family: sans-serif; font-size: 0.5em; text-anchor: middle; font-weight: bold; }
                
                .badge { padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
                .badge.green { background: #dcfce7; color: #166534; }
                .badge.orange { background: #fef3c7; color: #92400e; }
                .badge.red { background: #fee2e2; color: #991b1b; }
                .badge.gray { background: var(--bg-alt); color: var(--text-secondary); }
                
                /* Accordion */
                .accordion-content { background: var(--bg-page); border-bottom: 1px solid var(--border-color); display: none; padding: 1rem 1.5rem; }
                .accordion-content.open { display: block; animation: slideDown 0.3s ease-out; }
                @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
                .assignment-item { display: flex; align-items: center; justify-content: space-between; padding: 0.8rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 0.5rem; }
                .assignment-item:last-child { margin-bottom: 0; }
                .assign-title { font-weight: 600; color: var(--text-primary); font-size: 0.9rem; }
                .assign-stats { display: flex; gap: 2rem; font-size: 0.85rem; color: var(--text-secondary); }
                .assign-stats span strong { color: var(--text-primary); }
                .btn-view { background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--primary-color); cursor: pointer; transition: all 0.2s; }
                .btn-view:hover { background: #eff6ff; border-color: #bfdbfe; }
                
                /* Drawer */
                .drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); z-index: 100; display: none; opacity: 0; transition: opacity 0.3s; }
                .drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 100%; max-width: 600px; background: var(--bg-card); z-index: 101; box-shadow: -4px 0 15px rgba(0,0,0,0.1); transform: translateX(100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; }
                .drawer-overlay.active { display: block; opacity: 1; }
                .drawer.active { transform: translateX(0); }
                .drawer-header { padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-page); }
                .drawer-body { flex-grow: 1; overflow-y: auto; padding: 0; background: var(--bg-alt); }
                .drawer-close { background: none; border: none; font-size: 1.2rem; color: var(--text-secondary); cursor: pointer; }
                .drawer-filters { padding: 1rem 1.5rem; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; gap: 0.5rem; flex-wrap: wrap; }
                .student-list { padding: 1rem; }
                .student-card { background: var(--bg-card); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
                .student-card .info { display: flex; align-items: center; gap: 1rem; flex-grow: 1; }
                
                /* Layout utilities */
                .mb-1 { margin-bottom: 0.25rem; } .mb-2 { margin-bottom: 0.5rem; } .mb-4 { margin-bottom: 1rem; }
                .flex { display: flex; } .items-center { align-items: center; } .gap-2 { gap: 0.5rem; } .gap-4 { gap: 1rem; }
                .text-sm { font-size: 0.875rem; } .text-xs { font-size: 0.75rem; } .text-muted { color: var(--text-secondary); }
            </style>
            
            <div class="overview-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Assignment Overview</h2>
                </div>
                <button onclick="exportStudentAssignments()" style="background: #10b981; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: background 0.2s;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </button>
            </div>
            
            <div class="overview-filters">
                <select id="ao-dept" onchange="fetchAOData()">
                    <option value="ALL">All Departments</option>
                    <?php foreach($db['departments'] ?? [] as $d): ?>
                        <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="ao-year" onchange="fetchAOData()">
                    <option value="ALL">All Years</option>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
                <select id="ao-sem" onchange="fetchAOData()">
                    <option value="ALL">All Semesters</option>
                    <option value="1">Semester 1</option>
                    <option value="2">Semester 2</option>
                </select>
                <select id="ao-div" onchange="fetchAOData()">
                    <option value="ALL">All Divisions</option>
                    <option value="A">Div A</option>
                    <option value="B">Div B</option>
                    <option value="C">Div C</option>
                    <option value="D">Div D</option>
                </select>
                <div class="search-box">
                    <i class="fa-solid fa-search" style="color: var(--text-muted);"></i>
                    <input type="text" id="ao-search" placeholder="Search Student..." onkeyup="debounceFetchAO()">
                </div>
            </div>
            
            <!-- Student List Container -->
            <div id="ao-student-list-container" style="margin-bottom: 2rem;">
                <div class="panel">
                    <div class="panel-body" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                        <i class="fa-solid fa-users fa-3x" style="margin-bottom: 1rem; color: #cbd5e1;"></i>
                        <h4>Waiting for Filters</h4>
                        <p style="font-size: 0.9rem;">Please select filters above to load the student list.</p>
                    </div>
                </div>
            </div>
            

            
            <div class="panel" style="margin-bottom: 2rem;">
                <div class="panel-header">
                    <h3 class="panel-title">Recent Assignments Summary</h3>
                </div>
                <div class="panel-body">
                    <table class="data-table" style="width: 100%; border-collapse: collapse;">
                        <thead style="background: var(--bg-page); border-bottom: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-secondary);">
                            <tr>
                                <th style="padding: 1rem; text-align: left;">Assignment Title</th>
                                <th style="padding: 1rem; text-align: left;">Subject</th>
                                <th style="padding: 1rem; text-align: left;">Total Students</th>
                                <th style="padding: 1rem; text-align: left;">Submitted</th>
                                <th style="padding: 1rem; text-align: left;">Pending</th>
                                <th style="padding: 1rem; text-align: center;">Completion</th>
                            </tr>
                        </thead>
                        <tbody id="ao-recent-table" style="font-size: 0.9rem;">
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div style="text-align: center; color: #166534; background: #dcfce7; padding: 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; margin-bottom: 2rem;">
                <i class="fa-solid fa-check-circle"></i> All data is real-time and updated as per student submissions
            </div>
            
            <!-- Drawer -->
            <div class="drawer-overlay" id="ao-drawer-overlay" onclick="closeAODrawer()"></div>
            <div class="drawer" id="ao-drawer">
                <div class="drawer-header">
                    <div>
                        <h3 class="panel-title" id="drawer-assign-title">Assignment Title</h3>
                        <p class="panel-subtitle" id="drawer-assign-subtitle">Subject Name</p>
                    </div>
                    <button class="drawer-close" onclick="closeAODrawer()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="drawer-filters">
                    <div class="search-box" style="padding: 0.3rem 0.6rem;">
                        <i class="fa-solid fa-search" style="font-size: 0.8rem;"></i>
                        <input type="text" id="drawer-search" placeholder="Search students..." onkeyup="renderAODrawerStudents()">
                    </div>
                    <select id="drawer-status-filter" style="border: 1px solid var(--border-color); border-radius: 6px; outline: none; padding: 0.3rem 0.5rem; font-size: 0.85rem;" onchange="renderAODrawerStudents()">
                        <option value="ALL">All Status</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Pending">Pending Evaluation</option>
                        <option value="Not Uploaded">Not Uploaded</option>
                    </select>
                </div>
                <div class="drawer-body">
                    <div class="student-list" id="drawer-student-list">
                        <!-- Injected via JS -->
                    </div>
                </div>
            </div>
            
            <!-- Total Students Modal -->
            <div class="modal" id="total-students-modal" style="z-index: 10000; padding: 0; background: var(--bg-page);">
                <div class="modal-content" style="max-width: 100vw; width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0; display: flex; flex-direction: column; margin: 0; box-shadow: none;">
                    <div class="modal-header" style="justify-content: flex-start; gap: 20px;">
                        <button class="close-modal" onclick="closeTotalStudentsModal()" style="border-radius: 8px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--bg-card); border: 1px solid var(--border-color);"><i class="fa-solid fa-arrow-left"></i></button>
                        <h3 style="margin: 0; font-size: 1.5rem;">Total Students</h3>
                    </div>
                    <div class="search-box" style="flex: none; margin-bottom: 15px; display: flex; align-items: center; background: var(--bg-page); padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                        <i class="fa-solid fa-search" style="color: var(--text-muted); margin-right: 10px;"></i>
                        <input type="text" id="ts-search" placeholder="Search students..." oninput="renderTotalStudentsList()" style="border: none; background: transparent; outline: none; width: 100%; color: var(--text-primary); font-size: 0.9rem;">
                    </div>
                    <div id="ts-list" style="overflow-y: auto; flex: 1; padding-right: 5px;">
                        <!-- Injected via JS -->
                    </div>
                </div>
            </div>

            <!-- Student Subjects Modal -->
            <div class="modal" id="student-subjects-modal" style="z-index: 10001; padding: 0; background: var(--bg-page);">
                <div class="modal-content" style="max-width: 100vw; width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0; display: flex; flex-direction: column; margin: 0; box-shadow: none;">
                    <div class="modal-header">
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <button class="close-modal" onclick="closeStudentSubjectsModal()" style="border-radius: 8px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--bg-card); border: 1px solid var(--border-color);"><i class="fa-solid fa-arrow-left"></i></button>
                            <div>
                                <h3 id="ssm-name" style="margin-bottom: 0.25rem; font-size: 1.5rem;">Student Name</h3>
                                <p id="ssm-prn" style="font-size: 13px; color: var(--text-secondary); margin: 0;">PRN</p>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button class="export-btn" onclick="downloadStudentExcel()"><i class="fa-solid fa-file-excel" style="color: #10b981;"></i> Download Excel</button>
                        </div>
                    </div>
                    <div id="ssm-body" style="overflow-y: auto; flex: 1; padding-right: 5px; margin-top: 15px;">
                        <!-- Injected via JS -->
                    </div>
                </div>
            </div>
            
            <script>
                let aoData = null;
                let aoDrawerData = [];
                let aoDebounceTimer;
                
                function debounceFetchAO() {
                    clearTimeout(aoDebounceTimer);
                    aoDebounceTimer = setTimeout(fetchAOData, 500);
                }

                function exportStudentAssignments() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    const search = document.getElementById('ao-search').value;
                    
                    const url = `api_admin_assignments.php?action=export_student_assignment_report&dept=${dept}&year=${year}&sem=${sem}&div=${div}&search=${encodeURIComponent(search)}`;
                    window.location.href = url;
                }
                
                async function fetchAOData() {
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    const search = document.getElementById('ao-search').value;
                    
                    document.getElementById('ao-student-list-container').innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i><p style="margin-top:1rem;">Loading student data...</p></div>';
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_dashboard_summary&dept=${dept}&year=${year}&sem=${sem}&div=${div}&search=${search}`);
                        const data = await res.json();
                        if (data.success) {
                            aoData = data;
                            renderAODashboard();
                        }
                    } catch (e) {
                        console.error('Error fetching data', e);
                    }
                }
                
                async function toggleSubjectAssignments(studentId, subjName, event) {
                    if(event) event.stopPropagation();
                    
                    const safeName = subjName.replace(/[^a-zA-Z0-9]/g, '');
                    const container = document.getElementById(`assign-${studentId}-${safeName}`);
                    const icon = document.getElementById(`icon-${studentId}-${safeName}`);
                    
                    if (!container) return;
                    
                    if (container.style.display === 'none') {
                        container.style.display = 'block';
                        if(icon) icon.style.transform = 'rotate(180deg)';
                        
                        if (container.innerHTML.includes('fa-spinner')) {
                            const dept = document.getElementById('ao-dept').value;
                            const div = document.getElementById('ao-div').value;
                            
                            try {
                                const res = await fetch(`api_admin_assignments.php?action=get_student_subject_assignments&student_id=${studentId}&subject_name=${encodeURIComponent(subjName)}&dept=${dept}&div=${div}`);
                                const data = await res.json();
                                
                                if (data.success && data.assignments) {
                                    if (data.assignments.length === 0) {
                                        container.innerHTML = '<div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; font-style: italic;">No assignments created yet</div>';
                                    } else {
                                        let h = '<ul style="list-style: none; padding: 0; margin: 0;">';
                                        data.assignments.forEach((a, index) => {
                                            const isSub = a.status === 'Submitted' || a.status === 'Graded';
                                            const isPend = a.status.includes('Pending Eval');
                                            const statusColor = isSub ? '#16a34a' : (isPend ? '#eab308' : '#ef4444');
                                            
                                            h += `
                                                <li style="font-size: 0.8rem; padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9;">
                                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                                        <span style="color: var(--text-primary); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 65%;" title="Assignment ${index + 1}">Assignment ${index + 1}</span>
                                                        <span style="background: ${statusColor}15; color: ${statusColor}; padding: 0.1rem 0.4rem; border-radius: 4px; font-weight: 600; font-size: 0.7rem;">${a.status}</span>
                                                    </div>
                                                    <div style="display: flex; justify-content: space-between; color: var(--text-muted); font-size: 0.7rem;">
                                                        <span>Due: ${a.due_date ? a.due_date : '-'}</span>
                                                        ${a.marks !== '-' ? `<span style="font-weight: 600; color: var(--text-primary);">Marks: ${a.marks.toString().split('/')[0].trim()}/10</span>` : ''}
                                                    </div>
                                                </li>
                                            `;
                                        });
                                        h += '</ul>';
                                        container.innerHTML = h;
                                    }
                                } else {
                                    container.innerHTML = '<div style="color: #ef4444; font-size:0.8rem; text-align: center;">Failed to load assignments.</div>';
                                }
                            } catch (e) {
                                container.innerHTML = '<div style="color: #ef4444; font-size:0.8rem; text-align: center;">Error loading data.</div>';
                            }
                        }
                    } else {
                        container.style.display = 'none';
                        if(icon) icon.style.transform = 'rotate(0deg)';
                    }
                }

                async function toggleStudentSubjects(studentId) {
                    const row = document.getElementById(`ao-student-subjects-${studentId}`);
                    const icon = document.getElementById(`ao-student-icon-${studentId}`);
                    const content = document.getElementById(`ao-student-subjects-content-${studentId}`);
                    
                    if (row.style.display === 'none') {
                        row.style.display = 'table-row';
                        icon.style.transform = 'rotate(180deg)';
                        
                        if (content.innerHTML.includes('fa-spinner')) {
                            const dept = document.getElementById('ao-dept').value;
                            const div = document.getElementById('ao-div').value;
                            
                            try {
                                const res = await fetch(`api_admin_assignments.php?action=get_student_subjects&student_id=${studentId}&dept=${dept}&div=${div}`);
                                const data = await res.json();
                                
                                if (data.success && data.subjects) {
                                    if (data.subjects.length === 0) {
                                        content.innerHTML = '<div style="padding: 1rem;">No subjects assigned.</div>';
                                        return;
                                    }
                                    let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem 2rem;">';
                                    data.subjects.forEach(subj => {
                                        const safeName = subj.name.replace(/[^a-zA-Z0-9]/g, '');
                                        html += `
                                            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-left: 3px solid var(--primary-color); border-radius: 8px; padding: 1rem; text-align: left; box-shadow: 0 2px 4px rgba(0,0,0,0.02); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.boxShadow='0 4px 6px rgba(0,0,0,0.05)'" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)'" onclick="toggleSubjectAssignments('${studentId}', '${subj.name.replace(/'/g, "\\'")}', event)">
                                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                                    <div style="flex: 1; overflow: hidden;">
                                                        <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${subj.name}">${subj.name}</div>
                                                        <div style="font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fa-solid fa-chalkboard-user" style="color: var(--primary-light);"></i> ${subj.faculty}</div>
                                                    </div>
                                                    <i class="fa-solid fa-chevron-down" id="icon-${studentId}-${safeName}" style="color: var(--text-muted); font-size: 0.8rem; transition: transform 0.2s; padding-top: 0.25rem; margin-left: 0.5rem;"></i>
                                                </div>
                                                <div id="assign-${studentId}-${safeName}" style="display: none; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                                                    <div style="text-align: center; color: var(--text-muted); font-size: 0.8rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>
                                                </div>
                                            </div>
                                        `;
                                    });
                                    html += '</div>';
                                    content.innerHTML = html;
                                } else {
                                    content.innerHTML = '<div style="color: #ef4444;">Failed to load subjects.</div>';
                                }
                            } catch (e) {
                                content.innerHTML = '<div style="color: #ef4444;">Error fetching data.</div>';
                            }
                        }
                    } else {
                        row.style.display = 'none';
                        icon.style.transform = 'rotate(0deg)';
                    }
                }

                function renderAODashboard() {
                    if (!aoData) return;
                    
                    const students = aoData.all_students || [];
                    let studentHtml = `
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Student List</h3>
                            <p class="panel-subtitle">Showing ${students.length} students based on filters</p>
                        </div>
                        <div class="panel-body" style="overflow-x: auto;">
                            <table class="data-table" style="width: 100%; border-collapse: collapse; min-width: 700px;">
                                <thead style="background: var(--bg-page); border-bottom: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-secondary);">
                                    <tr>
                                        <th style="padding: 1rem; text-align: left;">PRN Number</th>
                                        <th style="padding: 1rem; text-align: left;">Student Name</th>
                                        <th style="padding: 1rem; text-align: left;">Department</th>
                                        <th style="padding: 1rem; text-align: center;">Div</th>
                                    </tr>
                                </thead>
                                <tbody style="font-size: 0.9rem;">
                    `;

                    if (students.length === 0) {
                        studentHtml += `<tr><td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-inbox fa-2x" style="margin-bottom:1rem;opacity:0.5;"></i><br>No students found for these filters.</td></tr>`;
                    } else {
                        students.forEach(s => {
                            studentHtml += `
                                <tr style="border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s;" onclick="toggleStudentSubjects('${s.id}')">
                                    <td style="padding: 1rem; font-weight: 500; color: var(--text-muted);">${s.prn || s.id}</td>
                                    <td style="padding: 1rem; font-weight: 600; color: var(--text-primary);">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <img src="${s.photo}" alt="${s.name}" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                            ${s.name}
                                        </div>
                                    </td>
                                    <td style="padding: 1rem; color: var(--text-secondary); text-transform: uppercase;">${s.department}</td>
                                    <td style="padding: 1rem; text-align: center; font-weight: 600;">
                                        ${s.division}
                                        <i class="fa-solid fa-chevron-down" id="ao-student-icon-${s.id}" style="margin-left: 0.75rem; color: var(--text-muted); transition: transform 0.2s;"></i>
                                    </td>
                                </tr>
                                <tr id="ao-student-subjects-${s.id}" style="display: none; background: var(--bg-alt); border-bottom: 2px solid var(--primary-light);">
                                    <td colspan="4" style="padding: 0;">
                                        <div id="ao-student-subjects-content-${s.id}" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                                            <i class="fa-solid fa-spinner fa-spin"></i> Fetching subject details...
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    studentHtml += `</tbody></table></div></div>`;
                    
                    document.getElementById('ao-student-list-container').innerHTML = studentHtml;
                    // Removed old analytics UI per user request
                    
                    // Recent Table
                    let rtHtml = '';
                    if (aoData.recent_assignments.length === 0) {
                        rtHtml = `<tr><td colspan="6" style="padding: 1.5rem; text-align: center; color: var(--text-muted);">No recent assignments.</td></tr>`;
                    } else {
                        aoData.recent_assignments.forEach(ra => {
                            rtHtml += `
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 1rem; font-weight: 500; color: var(--text-primary);">${ra.title}</td>
                                    <td style="padding: 1rem; color: var(--text-secondary);">${ra.subject}<br><span style="font-size: 0.75rem; color: var(--text-muted);">${ra.faculty}</span></td>
                                    <td style="padding: 1rem; color: var(--text-secondary);">${ra.total_students}</td>
                                    <td style="padding: 1rem; color: #166534; font-weight: 600;">${ra.submitted}</td>
                                    <td style="padding: 1rem; color: #991b1b; font-weight: 600;">${ra.pending}</td>
                                    <td style="padding: 1rem; text-align: center;">
                                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.2rem;">${ra.completion}%</div>
                                        <div style="width: 100%; height: 4px; background: var(--bg-alt); border-radius: 2px; overflow: hidden;">
                                            <div style="width: ${ra.completion}%; height: 100%; background: #3b82f6;"></div>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    document.getElementById('ao-recent-table').innerHTML = rtHtml;
                }
                
                function toggleAccordion(id) {
                    const el = document.getElementById(id);
                    if (el.classList.contains('open')) {
                        el.classList.remove('open');
                    } else {
                        document.querySelectorAll('.accordion-content').forEach(e => e.classList.remove('open'));
                        el.classList.add('open');
                    }
                }
                
                async function openAODrawer(saId, title, subj) {
                    event.stopPropagation();
                    document.getElementById('drawer-assign-title').textContent = title;
                    document.getElementById('drawer-assign-subtitle').textContent = subj;
                    
                    document.getElementById('ao-drawer-overlay').classList.add('active');
                    document.getElementById('ao-drawer').classList.add('active');
                    
                    document.getElementById('drawer-student-list').innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i></div>';
                    
                    const dept = document.getElementById('ao-dept').value;
                    const year = document.getElementById('ao-year').value;
                    const sem = document.getElementById('ao-sem').value;
                    const div = document.getElementById('ao-div').value;
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_assignment_students&sa_id=${saId}&dept=${dept}&year=${year}&sem=${sem}&div=${div}`);
                        const data = await res.json();
                        if (data.success) {
                            aoDrawerData = data.students;
                            renderAODrawerStudents();
                        }
                    } catch (e) {
                        console.error('Error fetching students', e);
                        document.getElementById('drawer-student-list').innerHTML = '<div style="color: red;">Error loading students.</div>';
                    }
                }
                
                function closeAODrawer() {
                    document.getElementById('ao-drawer-overlay').classList.remove('active');
                    document.getElementById('ao-drawer').classList.remove('active');
                }
                
                function renderAODrawerStudents() {
                    const searchRaw = document.getElementById('drawer-search').value.toLowerCase().trim();
                    const search = searchRaw.replace(/^zprn/i, '');
                    const statusFilter = document.getElementById('drawer-status-filter').value;
                    
                    const filtered = aoDrawerData.filter(s => {
                        let ms = true;
                        if (searchRaw !== '') {
                            ms = s.name.toLowerCase().includes(searchRaw) || s.roll.toLowerCase().includes(search);
                        }
                        let mStat = true;
                        if (statusFilter !== 'ALL') {
                            if (statusFilter === 'Pending') mStat = (s.status === 'Pending Evaluation' || s.status === 'Pending');
                            else mStat = (s.status === statusFilter);
                        }
                        return ms && mStat;
                    });
                    
                    let html = '';
                    if (filtered.length === 0) {
                        html = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);">No students found.</div>';
                    } else {
                        filtered.forEach(s => {
                            let badgeClass = 'gray';
                            if (s.status === 'Submitted' || s.status === 'Evaluated') badgeClass = 'green';
                            if (s.status === 'Pending' || s.status === 'Pending Evaluation') badgeClass = 'orange';
                            
                            html += `
                                <div class="student-card" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid var(--border-color);">
                                    <div class="info" style="display: flex; align-items: center; gap: 1rem;">
                                        <img src="${s.photo}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.2rem;">${s.name}</div>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary);">${s.roll} • <span class="badge ${badgeClass}" style="font-size: 0.65rem;">${s.status}</span></div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 700; color: ${badgeClass === 'green' ? '#166534' : '#64748b'}; font-size: 1.1rem;">${s.marks}</div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">${s.submitted_at}</div>
                                    </div>
                                </div>
                            `;
                        });
                    }
                    document.getElementById('drawer-student-list').innerHTML = html;
                }

                // Total Students Modal functions
                function openTotalStudentsModal() {
                    document.getElementById('total-students-modal').classList.add('active');
                    document.getElementById('ts-search').value = '';
                    renderTotalStudentsList();
                }

                function closeTotalStudentsModal() {
                    document.getElementById('total-students-modal').classList.remove('active');
                }

                function renderTotalStudentsList() {
                    if (!aoData || !aoData.all_students) return;
                    const searchRaw = document.getElementById('ts-search').value.toLowerCase().trim();
                    const search = searchRaw.replace(/^(zprn|\d{3}[a-z]{3})/i, '');
                    const listEl = document.getElementById('ts-list');
                    let html = '';
                    
                    const filtered = aoData.all_students.filter(s => 
                        String(s.name).toLowerCase().includes(searchRaw) || 
                        String(s.prn).toLowerCase().includes(search) ||
                        String(s.id).toLowerCase().includes(search)
                    );
                    
                    if (filtered.length === 0) {
                        html = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);">No students found.</div>';
                    } else {
                        filtered.forEach(s => {
                            html += `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.2s; border-radius: 8px; margin-bottom: 0.5rem;" onclick="openStudentSubjectsModal('${s.id}', '${s.name.replace(/'/g, "\\'")}', '${s.prn}', '${s.department}', '${s.year}', '${s.division}')" onmouseover="this.style.background='var(--bg-alt)'" onmouseout="this.style.background='transparent'">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <img src="${s.photo}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color);">
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.2rem;">${s.name}</div>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary);">${s.prn} • ${s.department} (Div ${s.division})</div>
                                        </div>
                                    </div>
                                    <div><i class="fa-solid fa-chevron-right" style="color: var(--text-muted);"></i></div>
                                </div>
                            `;
                        });
                    }
                    listEl.innerHTML = html;
                }

                let currentStudentSubjects = [];
                let currentStudentDetails = {};

                async function openStudentSubjectsModal(studentId, studentName, studentPrn, studentDept, studentYear, studentDiv) {
                    currentStudentDetails = { name: studentName, prn: studentPrn, dept: studentDept, year: studentYear, div: studentDiv };
                    document.getElementById('ssm-name').textContent = studentName;
                    document.getElementById('ssm-prn').innerHTML = `ZPRN: <strong>${studentId}</strong> &nbsp;|&nbsp; Dept: <strong>${studentDept}</strong> &nbsp;|&nbsp; Year: <strong>${studentYear}</strong> &nbsp;|&nbsp; Div: <strong>${studentDiv}</strong>`;
                    document.getElementById('student-subjects-modal').classList.add('active');
                    
                    const bodyEl = document.getElementById('ssm-body');
                    bodyEl.innerHTML = '<div style="padding: 3rem; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-circle-notch fa-spin fa-2x"></i></div>';
                    
                    const dept = document.getElementById('ao-dept').value;
                    const div = document.getElementById('ao-div').value;
                    
                    try {
                        const res = await fetch(`api_admin_assignments.php?action=get_student_subjects&student_id=${studentId}&dept=${dept}&div=${div}`);
                        const data = await res.json();
                        if (data.success) {
                            renderStudentSubjects(data.subjects);
                        } else {
                            bodyEl.innerHTML = `<div style="color: red; padding: 1rem; text-align: center;">${data.error || 'Failed to load subjects'}</div>`;
                        }
                    } catch (e) {
                        bodyEl.innerHTML = '<div style="color: red; padding: 1rem; text-align: center;">Error communicating with server.</div>';
                    }
                }

                function closeStudentSubjectsModal() {
                    document.getElementById('student-subjects-modal').classList.remove('active');
                }
                
                function renderStudentSubjects(subjects) {
                    currentStudentSubjects = subjects;
                    const bodyEl = document.getElementById('ssm-body');
                    if (!subjects || subjects.length === 0) {
                        bodyEl.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--text-muted);">No subjects found for this student.</div>';
                        return;
                    }
                    
                    let html = '';
                    subjects.forEach((sub, index) => {
                        let badgeColor = 'gray';
                        if (sub.status === 'Excellent' || sub.status === 'Good') badgeColor = 'green';
                        if (sub.status === 'Needs Attention') badgeColor = 'red';
                        
                        // Set progress bar color class names based on standard admin CSS
                        let pbColor = '#3b82f6';
                        if (sub.completion >= 80) pbColor = '#22c55e';
                        else if (sub.completion < 50) pbColor = '#ef4444';
                        
                        html += `
                            <div style="background: var(--bg-page); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; transition: box-shadow 0.2s;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; cursor: pointer;" onclick="document.getElementById('ssm-assign-${index}').style.display = document.getElementById('ssm-assign-${index}').style.display === 'none' ? 'block' : 'none'; const icon = document.getElementById('ssm-icon-${index}'); if(icon.style.transform === 'rotate(180deg)'){icon.style.transform='rotate(0deg)';}else{icon.style.transform='rotate(180deg)';}">
                                    <div>
                                        <div style="font-weight: 700; color: var(--text-primary); font-size: 1.05rem; margin-bottom: 0.25rem;">${sub.name}</div>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary);"><i class="fa-solid fa-chalkboard-user"></i> ${sub.faculty}</div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <span class="badge badge-${badgeColor}" style="padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: ${badgeColor==='green'?'#dcfce7':(badgeColor==='red'?'#ffe4e6':'#f1f5f9')}; color: ${badgeColor==='green'?'#166534':(badgeColor==='red'?'#991b1b':'#475569')};">${sub.status}</span>
                                        <i id="ssm-icon-${index}" class="fa-solid fa-chevron-down" style="color: var(--text-muted); transition: transform 0.2s;"></i>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; background: #fff; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                                    <div style="text-align: center;">
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Assignments</div>
                                        <div style="font-weight: 700; color: var(--text-primary); font-size: 1.1rem;">${sub.total_assignments}</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Submitted</div>
                                        <div style="font-weight: 700; color: #166534; font-size: 1.1rem;">${sub.submitted}</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Pending</div>
                                        <div style="font-weight: 700; color: #991b1b; font-size: 1.1rem;">${sub.pending}</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Avg Marks</div>
                                        <div style="font-weight: 700; color: #3b82f6; font-size: 1.1rem;">${sub.avg_marks}</div>
                                    </div>
                                </div>
                                <div style="margin-top: 1rem; font-size: 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--text-secondary);">Completion</span>
                                    <span style="font-weight: 600; color: var(--text-primary);">${sub.completion}%</span>
                                </div>
                                <div style="width: 100%; height: 6px; background: #e2e8f0; border-radius: 3px; margin-top: 0.5rem; overflow: hidden; margin-bottom: 1rem;">
                                    <div style="height: 100%; width: ${sub.completion}%; background: ${pbColor}; border-radius: 3px;"></div>
                                </div>
                                <div id="ssm-assign-${index}" style="border-top: 1px solid var(--border-color); padding-top: 1rem; display: none; margin-top: 1rem;">
                                    <h4 style="font-size: 0.9rem; color: var(--text-primary); margin-bottom: 0.5rem;">Assignments</h4>
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                        ${sub.assignments && sub.assignments.length > 0 ? sub.assignments.map(a => `
                                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background: var(--bg-card); border-radius: 6px; border: 1px solid var(--border-color);">
                                                <div style="flex: 1;">
                                                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-primary);">${a.title}</div>
                                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">Due: ${a.due_date}</div>
                                                </div>
                                                <div style="text-align: right; margin-right: 1rem;">
                                                    <div style="font-size: 0.8rem; font-weight: 600; color: ${a.marks !== '-' && a.marks !== 'Pending' ? '#166534' : '#64748b'};">Marks: ${a.marks}</div>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);">${a.submitted_at !== '-' ? a.submitted_at : 'Not Submitted'}</div>
                                                </div>
                                                <span class="badge ${a.status === 'Graded' ? 'badge-green' : (a.status === 'Pending Eval' ? 'badge-orange' : 'badge-red')}" style="font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; background: ${a.status === 'Graded' ? '#dcfce7' : (a.status === 'Pending Eval' ? '#fef3c7' : '#fee2e2')}; color: ${a.status === 'Graded' ? '#166534' : (a.status === 'Pending Eval' ? '#92400e' : '#991b1b')};">${a.status}</span>
                                            </div>
                                        `).join('') : '<div style="font-size: 0.85rem; color: var(--text-muted);">No assignments found.</div>'}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    bodyEl.innerHTML = html;
                }

                function downloadStudentExcel() {
                    if (!currentStudentSubjects || currentStudentSubjects.length === 0) return;
                    let csv = 'Subject,Faculty,Total Assignments,Submitted,Pending,Avg Marks,Completion %,Assignment Title,Due Date,Status,Marks,Submitted At\n';
                    
                    currentStudentSubjects.forEach(sub => {
                        const baseRow = `"${sub.name}","${sub.faculty}",${sub.total_assignments},${sub.submitted},${sub.pending},${sub.avg_marks},${sub.completion}%`;
                        if (sub.assignments && sub.assignments.length > 0) {
                            sub.assignments.forEach(a => {
                                csv += `${baseRow},"${a.title}","${a.due_date}","${a.status}","${a.marks}","${a.submitted_at}"\n`;
                            });
                        } else {
                            csv += `${baseRow},None,-,-,-,-\n`;
                        }
                    });
                    
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `${currentStudentDetails.name}_Assignments.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }
                
                function exportAOCSV() {
                    if (!aoData || aoData.subject_summary.length === 0) {
                        alert("No data to export");
                        return;
                    }
                    
                    let csv = [];
                    aoData.subject_summary.forEach(subj => {
                        subj.assignments.forEach(ass => {
                            csv.push({
                                Subject: subj.subject_name,
                                Faculty: subj.faculty_name,
                                Assignment: ass.title,
                                Total_Students: subj.expected_submissions / subj.total_assignments, // approx class size
                                Submitted: ass.submitted,
                                Pending: ass.pending,
                                Completion: ass.completion + '%'
                            });
                        });
                    });
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'api_admin_assignments.php?action=export_csv';
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'data';
                    input.value = JSON.stringify(csv);
                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }

                // Initial fetch
                setTimeout(fetchAOData, 100);
            </script>
        </div>

        <div id="view-system-configuration" class="app-view">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">System Configuration</h2>
                    <p style="color: var(--text-secondary); font-size: 0.95rem;">Configure system settings and preferences.</p>
                </div>
            </div>

            <?php
            $config_groups = [
                'College Information' => [
                    'icon' => '<i class="fa-solid fa-building-columns"></i>',
                    'color' => '#4f46e5', 'bg' => '#e0e7ff',
                    'desc' => 'View and update basic information about the college.',
                    'settings' => [
                        'site_name' => 'College Name',
                        'address' => 'Address',
                        'email_from' => 'Email',
                        'phone' => 'Phone',
                        'website' => 'Website',
                        'established_year' => 'Established Year'
                    ]
                ],
                'Academic Settings' => [
                    'icon' => '<i class="fa-solid fa-graduation-cap"></i>',
                    'color' => '#8b5cf6', 'bg' => '#f3e8ff',
                    'desc' => 'Manage academic year, semesters, departments and courses.',
                    'settings' => [
                        'academic_year' => 'Academic Year',
                        'current_semester' => 'Current Semester',
                        'total_departments' => 'Departments',
                        'total_courses' => 'Courses',
                        'sections_divisions' => 'Sections / Divisions',
                        'grading_system' => 'Grading System'
                    ]
                ],
                'User & Role Management' => [
                    'icon' => '<i class="fa-solid fa-user-group"></i>',
                    'color' => '#3b82f6', 'bg' => '#dbeafe',
                    'desc' => 'Manage users, roles and their access to the system.',
                    'settings' => [
                        'total_users' => 'Total Users',
                        'admin_users' => 'Admin Users',
                        'faculty_users' => 'Faculty Users',
                        'student_users' => 'Student Users',
                        'roles_defined' => 'Roles'
                    ]
                ],
                'System Maintenance' => [
                    'icon' => '<i class="fa-solid fa-wrench"></i>',
                    'color' => '#8b5cf6', 'bg' => '#f3e8ff',
                    'desc' => 'System health, cache and logs management.',
                    'settings' => [
                        'system_status' => 'System Status',
                        'cache' => 'Cache',
                        'database_size' => 'Database Size',
                        'system_logs' => 'System Logs'
                    ]
                ],
                'Notification Settings' => [
                    'icon' => '<i class="fa-regular fa-bell"></i>',
                    'color' => '#6366f1', 'bg' => '#e0e7ff',
                    'desc' => 'Configure email, SMS and in-app notifications.',
                    'settings' => [
                        'email_notifications' => 'Email Notifications',
                        'sms_notifications' => 'SMS Notifications',
                        'in_app_notifications' => 'In-App Notifications',
                        'notice_duration' => 'Notice Display Duration'
                    ]
                ],
                'Security Settings' => [
                    'icon' => '<i class="fa-solid fa-shield"></i>',
                    'color' => '#8b5cf6', 'bg' => '#f3e8ff',
                    'desc' => 'Manage security preferences and login settings.',
                    'settings' => [
                        'password_policy' => 'Password Policy',
                        'two_factor_auth' => 'Two-Factor Authentication',
                        'session_timeout' => 'Session Timeout',
                        'login_history' => 'Login History'
                    ]
                ],
                'Backup & Restore' => [
                    'icon' => '<i class="fa-solid fa-cloud-arrow-up"></i>',
                    'color' => '#3b82f6', 'bg' => '#dbeafe',
                    'desc' => 'Backup and restore system data and settings.',
                    'settings' => [
                        'last_backup' => 'Last Backup',
                        'backup_frequency' => 'Backup Frequency',
                        'auto_backup' => 'Auto Backup',
                        'restore_points' => 'Restore Points'
                    ]
                ],
                'System Preferences' => [
                    'icon' => '<i class="fa-solid fa-sliders"></i>',
                    'color' => '#8b5cf6', 'bg' => '#f3e8ff',
                    'desc' => 'Set system preferences and default options.',
                    'settings' => [
                        'language' => 'Language',
                        'date_format' => 'Date Format',
                        'default_timezone' => 'Time Zone',
                        'theme' => 'Theme'
                    ]
                ]
            ];
            ?>
            <style>
                .masonry-grid {
                    column-count: 2;
                    column-gap: 2rem;
                }
                .masonry-card {
                    break-inside: avoid;
                    background: var(--bg-card);
                    border-radius: 12px;
                    border: 1px solid #f1f5f9;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.02);
                    padding: 1.5rem;
                    margin-bottom: 1.5rem;
                    display: inline-block;
                    width: 100%;
                }
                @media (max-width: 1200px) {
                    .masonry-grid {
                        column-count: 1;
                    }
                }
            </style>
            
            <div class="masonry-grid">
                <?php foreach ($config_groups as $group_name => $group_data): ?>
                <div class="masonry-card">
                    <!-- Card Header -->
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                        <div style="background: <?= $group_data['bg'] ?>; color: <?= $group_data['color'] ?>; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
                            <?= $group_data['icon'] ?>
                        </div>
                        <div>
                            <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.15rem;"><?= htmlspecialchars($group_name) ?></h3>
                            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0; line-height: 1.4;"><?= htmlspecialchars($group_data['desc']) ?></p>
                        </div>
                    </div>
                    
                    <!-- Card Body -->
                    <div>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tbody>
                                <?php foreach ($group_data['settings'] as $key => $label): 
                                    $val = $db['settings'][$key] ?? '';
                                ?>
                                <tr style="border-bottom: 1px dashed #f1f5f9;">
                                    <td style="padding: 0.75rem 0; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); width: 45%;"><?= htmlspecialchars($label) ?></td>
                                    <td style="padding: 0.75rem 0; font-size: 0.85rem; color: var(--text-secondary);" id="val-<?= $key ?>">
                                        <?php if ($key === 'system_status'): ?>
                                            <span style="background: #dcfce7; color: #16a34a; padding: 0.15rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Healthy</span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($val) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem 0; text-align: right; width: 60px;">
                                        <?php 
                                        $readonly_keys = ['system_status', 'database_size', 'total_users', 'admin_users', 'faculty_users', 'student_users', 'total_departments', 'total_courses', 'roles_defined'];
                                        if (!in_array($key, $readonly_keys)): 
                                        ?>
                                        <a href="#" onclick="openSettingModal('<?= $key ?>', '<?= addslashes(htmlspecialchars($label)) ?>', '<?= addslashes(htmlspecialchars($val)) ?>'); return false;" style="color: #4f46e5; font-size: 0.8rem; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; transition: color 0.2s;" onmouseover="this.style.color='#3730a3'" onmouseout="this.style.color='#4f46e5'">
                                            <i class="fa-solid fa-pen" style="font-size: 0.75rem;"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New User</h3>
                <button class="close-modal" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="roleSelect" onchange="handleRoleChange()" required>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required placeholder="Enter full name">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="Enter email address">
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" required pattern="[0-9]{10}" maxlength="10" minlength="10" title="Mobile Number must be exactly 10 digits." placeholder="Enter phone number">
                </div>

                <div class="form-group">
                    <label>Department</label>
                    <select name="department" id="deptSelect" onchange="updatePRN()" required>
                        <?php foreach($db['departments'] ?? [] as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="prnGroup">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; color: var(--text-primary);">
                        Automatic Student PRN
                    </label>
                    <div style="display: flex; align-items: center; gap: 0.6rem; background: var(--bg-page); border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 0.6rem 0.75rem; margin-bottom: 0.5rem;">
                        <input type="checkbox" id="autoPrnToggle" checked onchange="toggleAutoPrnMode(this)" style="width: 18px; height: 18px; accent-color: #4f46e5; cursor: pointer;">
                        <label for="autoPrnToggle" style="font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; margin: 0; user-select: none;">Auto-generate PRN by Department</label>
                        <span style="margin-left: auto; font-size: 0.75rem; color: #4f46e5; font-weight: 700; background: #e0e7ff; padding: 2px 8px; border-radius: 12px;">Active</span>
                    </div>
                    <div>
                        <input type="text" name="prn" id="prnInput" readonly value="<?= htmlspecialchars(generate_next_prn($db, 'Information Technology')) ?>" style="background-color: #e0e7ff; font-weight: 700; color: #3730a3; border: 1.5px solid #6366f1; cursor: not-allowed; font-size: 1rem; letter-spacing: 0.5px; width: 100%; padding: 0.65rem 0.75rem; border-radius: 8px;" placeholder="Auto-generated PRN">
                    </div>
                </div>

                <!-- Faculty specific fields -->
                <div id="facultyFields" style="display: none;">
                    <div class="form-group">
                        <label>Designation</label>
                        <select name="designation">
                            <option value="Assistant Professor">Assistant Professor</option>
                            <option value="Associate Professor">Associate Professor</option>
                            <option value="Professor">Professor</option>
                            <option value="Professor & HOD">Professor & HOD</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Subjects</label>
                        <input type="text" name="subjects" placeholder="e.g. Data Structures, OS">
                    </div>
                </div>

                <button type="submit" class="submit-btn">Save User</button>
            </form>
        </div>
    </div>

    <!-- Import Users Modal -->
    <div class="modal" id="importUsersModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Bulk Import Students</h3>
                <button class="close-modal" onclick="closeImportModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_students">
                
                <div class="form-group">
                    <label>Select CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required style="padding: 0.5rem; border: 1.5px solid #cbd5e1; border-radius: 8px; width: 100%;">
                </div>
                
                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem; line-height: 1.4;">
                    <strong>CSV Columns expected:</strong><br>
                    Student Name, ZPRN, Division, Semester, Department
                </div>

                <button type="submit" class="submit-btn" style="background-color: #10b981;">Import Students</button>
            </form>
        </div>
    </div>

    <!-- Add Department Modal -->
    <div class="modal" id="addDeptModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Department</h3>
                <button class="close-modal" onclick="closeDeptModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_department">
                
                <div class="form-group">
                    <label>Department Name</label>
                    <input type="text" name="dept_name" required placeholder="e.g. Mechanical Engineering">
                </div>

                <div class="form-group">
                    <label>Department Code</label>
                    <input type="text" name="dept_code" required placeholder="e.g. ME-ENGG">
                </div>

                <div class="form-group">
                    <label>Intake Capacity</label>
                    <input type="number" name="intake" required placeholder="e.g. 120">
                </div>

                <div class="form-group">
                    <label>HOD Name</label>
                    <input type="text" name="hod_name" required placeholder="e.g. Dr. Rajesh Kumar">
                </div>

                <button type="submit" class="submit-btn">Save Department</button>
            </form>
        </div>
    </div>

    <!-- Edit Setting Modal -->
    <div class="modal" id="editSettingModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Setting</h3>
                <button class="close-modal" onclick="closeSettingModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_setting">
                <input type="hidden" name="setting_key" id="editSettingKey">
                
                <div class="form-group">
                    <label id="editSettingNameLabel">Setting Name</label>
                    <input type="text" name="setting_value" id="editSettingValue" required placeholder="Enter new value">
                </div>

                <button type="submit" class="submit-btn">Save Setting</button>
            </form>
        </div>
    </div>

    <!-- Profile Modal HTML -->
    <div id="profileModal" class="profile-modal-overlay">
        <div class="profile-card">
            <div class="profile-card-header">
                <button class="close-profile-btn" onclick="closeUserProfile()"><i class="fa-solid fa-xmark"></i></button>
                <div class="profile-avatar-wrapper" style="display: flex; justify-content: center; align-items: center; color: white; font-size: 42px; font-weight: 800; border: 3px solid white;">
                    <span id="pm-initials"></span>
                </div>
                <h2 id="pm-name">Name</h2>
                <span id="pm-role" class="pm-badge">Role</span>
            </div>
            <div class="profile-card-body">
                <div class="pm-info-row">
                    <div class="pm-info-icon"><i class="fa-regular fa-envelope"></i></div>
                    <div class="pm-info-text">
                        <label>Email</label>
                        <p id="pm-email">email@example.com</p>
                    </div>
                </div>
                <div class="pm-info-row">
                    <div class="pm-info-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="pm-info-text">
                        <label>Phone</label>
                        <p id="pm-phone">+91 000000000</p>
                    </div>
                </div>
                <div class="pm-info-row">
                    <div class="pm-info-icon"><i class="fa-solid fa-building"></i></div>
                    <div class="pm-info-text">
                        <label>Department</label>
                        <p id="pm-department">IT</p>
                    </div>
                </div>
                <div class="pm-info-row pm-prn-row">
                    <div class="pm-info-icon"><i class="fa-solid fa-id-card"></i></div>
                    <div class="pm-info-text">
                        <label>PRN</label>
                        <p id="pm-prn">N/A</p>
                    </div>
                </div>
                <div class="pm-info-row pm-subjects-row">
                    <div class="pm-info-icon"><i class="fa-solid fa-book"></i></div>
                    <div class="pm-info-text">
                        <label>Subjects</label>
                        <p id="pm-subjects">N/A</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabId) {
            // Hide all views
            document.querySelectorAll('.app-view').forEach(view => {
                view.classList.remove('active');
            });
            // Remove active class from navs
            document.querySelectorAll('.nav-link').forEach(nav => {
                nav.classList.remove('active');
            });

            // Show selected view
            document.getElementById('view-' + tabId).classList.add('active');
            document.getElementById('nav-' + tabId).classList.add('active');

            // Update top banner dynamically
            const title = document.getElementById('banner-title');
            const desc = document.getElementById('banner-desc');

            if(tabId === 'dashboard') {
                title.innerText = 'Dashboard';
                desc.innerText = 'Welcome to the Admin Panel.';
            } else if (tabId === 'user-management') {
                title.innerText = 'User Management';
                desc.innerText = 'Manage IT Department students and faculty.';
            } else if (tabId === 'department-management') {
                title.innerText = 'Department Management';
                desc.innerText = 'Manage college departments and intake capacities.';
            } else if (tabId === 'notice-management') {
                title.innerText = 'Notice Management';
                desc.innerText = 'Create, publish, and manage college-wide notices.';
            } else if (tabId === 'report-generation') {
                title.innerText = 'Report Generation';
                desc.innerText = 'Generate and view various reports.';
            } else if (tabId === 'system-configuration') {
                title.innerText = 'System Configuration';
                desc.innerText = 'Manage global settings, security preferences, and system behavior.';
            } else if (tabId === 'student-marks') {
                title.innerText = 'Student Marks';
                desc.innerText = 'View and search student assignment marks by department, year, semester, and division.';
                if (typeof renderStudentMarks === 'function') renderStudentMarks();
            }
        }

        window.existingStudents = <?php echo json_encode($db['students'] ?? []); ?>;

        function getDeptPrefix(deptName) {
            if (!deptName) return 'ST';
            const clean = deptName.replace(/^Department of\s+/i, '').split(' - ')[0].trim();
            const map = {
                'Information Technology': 'IT',
                'Computer Engineering': 'CE',
                'Computer Science': 'CS',
                'Computer Science & Engineering': 'CSE',
                'Electronics & Telecommunication': 'ENTC',
                'Electronics Engineering': 'EXTC',
                'Mechanical Engineering': 'ME',
                'Civil Engineering': 'CV',
                'Electrical Engineering': 'EE',
                'Chemical Engineering': 'CHE',
                'AI & Data Science': 'AIDS',
                'Artificial Intelligence': 'AI'
            };
            if (map[clean]) return map[clean];
            const words = clean.replace(/[^a-zA-Z\s]/g, '').split(/\s+/);
            let initials = '';
            words.forEach(w => {
                if (w && !['of', 'and', '&'].includes(w.toLowerCase())) {
                    initials += w[0].toUpperCase();
                }
            });
            return initials || 'ST';
        }

        function toggleAutoPrnMode(checkbox) {
            const prnInput = document.getElementById('prnInput');
            if (!prnInput) return;
            if (checkbox.checked) {
                prnInput.readOnly = true;
                prnInput.style.backgroundColor = '#e0e7ff';
                prnInput.style.color = '#3730a3';
                prnInput.style.border = '1.5px solid #6366f1';
                prnInput.style.cursor = 'not-allowed';
                updatePRN();
            } else {
                prnInput.readOnly = false;
                prnInput.style.backgroundColor = '#ffffff';
                prnInput.style.color = '#0f172a';
                prnInput.style.border = '1.5px solid #cbd5e1';
                prnInput.style.cursor = 'text';
                prnInput.focus();
            }
        }

        function updatePRN() {
            const roleSelect = document.getElementById('roleSelect');
            if (!roleSelect) return;
            const role = roleSelect.value;
            const deptSelect = document.querySelector('#addUserModal select[name="department"]');
            const prnInput = document.getElementById('prnInput');
            const prnGroup = document.getElementById('prnGroup');
            const autoPrnToggle = document.getElementById('autoPrnToggle');

            if (role === 'student') {
                if (prnGroup) prnGroup.style.display = 'block';
                if (autoPrnToggle && !autoPrnToggle.checked) {
                    return;
                }
                const dept = deptSelect ? deptSelect.value : 'Information Technology';
                const prefix = getDeptPrefix(dept);
                
                let count = 0;
                if (window.existingStudents && Array.isArray(window.existingStudents)) {
                    window.existingStudents.forEach(s => {
                        const sPrefix = getDeptPrefix(s.dept || s.department || '');
                        if (sPrefix === prefix || (s.prn && s.prn.startsWith(prefix))) {
                            count++;
                        }
                    });
                }
                
                const nextNum = count + 1;
                const paddedNum = String(nextNum).padStart(4, '0');
                if (prnInput) prnInput.value = prefix + paddedNum;
            } else {
                if (prnGroup) prnGroup.style.display = 'none';
            }
        }

        // Modal functions
        function openModal() {
            document.getElementById('addUserModal').classList.add('active');
            handleRoleChange();
            updatePRN();
        }

        function closeModal() {
            document.getElementById('addUserModal').classList.remove('active');
        }

        function handleRoleChange() {
            const role = document.getElementById('roleSelect').value;
            const facultyFields = document.getElementById('facultyFields');
            
            if(role === 'faculty') {
                facultyFields.style.display = 'block';
            } else {
                facultyFields.style.display = 'none';
            }
            updatePRN();
        }

        // Close modal when clicking outside
        document.getElementById('addUserModal').addEventListener('click', function(e) {
            if(e.target === this) {
                closeModal();
            }
        });

        function openImportModal() {
            document.getElementById('importUsersModal').classList.add('active');
        }

        function closeImportModal() {
            document.getElementById('importUsersModal').classList.remove('active');
        }

        document.getElementById('importUsersModal').addEventListener('click', function(e) {
            if(e.target === this) {
                closeImportModal();
            }
        });

        function openDeptModal() {
            document.getElementById('addDeptModal').classList.add('active');
        }

        function closeDeptModal() {
            document.getElementById('addDeptModal').classList.remove('active');
        }

        document.getElementById('addDeptModal').addEventListener('click', function(e) {
            if(e.target === this) {
                closeDeptModal();
            }
        });
        
        // Edit Setting Modal
        function openSettingModal(key, name, value) {
            document.getElementById('editSettingKey').value = key;
            document.getElementById('editSettingNameLabel').innerText = name;
            document.getElementById('editSettingValue').value = value;
            document.getElementById('editSettingModal').classList.add('active');
        }

        function closeSettingModal() {
            document.getElementById('editSettingModal').classList.remove('active');
        }

        document.getElementById('editSettingModal').addEventListener('click', function(e) {
            if(e.target === this) {
                closeSettingModal();
            }
        });
        
        // Profile Modal JS
        function showUserProfile(element) {
            const userData = JSON.parse(element.getAttribute('data-user'));
            
            // Extract initials
            let cleanName = userData.name.replace(/^(Prof\.|Dr\.|Mr\.|Ms\.)\s+/i, '').trim();
            let parts = cleanName.split(/\s+/);
            let initials = '';
            if (parts.length === 1) {
                initials = parts[0].charAt(0).toUpperCase();
            } else {
                initials = parts[0].charAt(0).toUpperCase() + parts[parts.length - 1].charAt(0).toUpperCase();
            }
            document.getElementById('pm-initials').textContent = initials;
            document.getElementById('pm-name').textContent = userData.name;
            document.getElementById('pm-role').textContent = userData.role;
            document.getElementById('pm-email').textContent = userData.email;
            document.getElementById('pm-phone').textContent = userData.phone;
            document.getElementById('pm-department').textContent = userData.department;
            
            const prnRow = document.querySelector('.pm-prn-row');
            if(userData.role === 'Student') {
                document.querySelector('.pm-subjects-row').style.display = 'none';
                if(prnRow) {
                    prnRow.style.display = 'flex';
                    document.getElementById('pm-prn').textContent = userData.prn || 'N/A';
                }
            } else {
                document.querySelector('.pm-subjects-row').style.display = 'flex';
                document.getElementById('pm-subjects').textContent = userData.subjects;
                if(prnRow) {
                    prnRow.style.display = 'none';
                }
            }
            
            const modal = document.getElementById('profileModal');
            modal.classList.add('active');
        }

        function closeUserProfile() {
            document.getElementById('profileModal').classList.remove('active');
        }

        document.getElementById('profileModal').addEventListener('click', function(e) {
            if(e.target === this) {
                closeUserProfile();
            }
        });
        
        // Show active tab if a success message exists (meaning they just added a record)
        <?php if($success_message): ?>
            switchTab('<?php echo isset($active_tab) ? $active_tab : 'user-management'; ?>');
        <?php endif; ?>
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
            updatePRN();
            if (localStorage.getItem('theme_preference') === 'dark') {
                document.body.classList.add('dark-mode');
                updateThemeIcon(true);
            }
        });
    </script>
</body>
</html>
