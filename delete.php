<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    $type = $_POST['type'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $raw_id = $_POST['id'] ?? '';
    $db = get_db();
    
    $allowed_types = ['leaves', 'grievances', 'notices', 'assignments', 'subject_assignments', 'faculty'];
    
    if (in_array($type, $allowed_types) && ($id > 0 || !empty($raw_id))) {
        $found = false;
        $deleted_username = '';
        if (isset($db[$type])) {
            foreach ($db[$type] as $key => $item) {
                if (isset($item['id']) && ($item['id'] == $id || (string)$item['id'] === (string)$raw_id)) {
                    $deleted_username = $item['username'] ?? '';
                    unset($db[$type][$key]);
                    $db[$type] = array_values($db[$type]);
                    $found = true;
                    break;
                }
            }
        }
        
        // Cascading deletion for subject assignments
        if ($type === 'subject_assignments' && $found) {
            if (isset($db['assignment_submissions'])) {
                $db['assignment_submissions'] = array_values(array_filter($db['assignment_submissions'], function($sub) use ($id) {
                    return intval($sub['subject_assignment_id'] ?? 0) !== $id;
                }));
            }
            if (isset($db['assignment_grievances'])) {
                $db['assignment_grievances'] = array_values(array_filter($db['assignment_grievances'], function($g) use ($id) {
                    return intval($g['subject_assignment_id'] ?? 0) !== $id;
                }));
            }
            if (isset($db['grievances'])) {
                $db['grievances'] = array_values(array_filter($db['grievances'], function($mg) use ($id) {
                    return intval($mg['subject_assignment_id'] ?? 0) !== $id;
                }));
            }
        }

        // MySQL DB Consistency for Faculty
        if ($type === 'faculty' && $found && !empty($deleted_username)) {
            try {
                $stmt = $pdo->prepare("DELETE FROM faculty WHERE username = ?");
                $stmt->execute([$deleted_username]);
            } catch (PDOException $e) {
                // Ignore errors gracefully for local fallback
            }
        }
        
        if ($found) {
            save_db($db);
            $_SESSION['success_message'] = ucfirst(rtrim($type, 's')) . " deleted successfully.";
            
            if ($type === 'faculty') {
                $_SESSION['success_message'] = "Faculty member deleted successfully.";
                $_SESSION['active_tab'] = 'user-management';
            }
        } else {
            $_SESSION['error_message'] = "Item not found.";
        }
    } else {
        $_SESSION['error_message'] = "Invalid delete request.";
    }
    
    // Redirect back to the referrer
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header("Location: " . $referer);
    exit;
}
