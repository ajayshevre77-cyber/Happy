<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$otp = $_POST['otp'] ?? '';

if (empty($username) || empty($new_password) || empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'Username, new password, and OTP are required.']);
    exit;
}

if (!isset($_SESSION['reset_otp']) || !isset($_SESSION['reset_username']) || !isset($_SESSION['reset_input']) || !isset($_SESSION['reset_otp_expiry'])) {
    echo json_encode(['success' => false, 'message' => 'No OTP request found. Please request a new OTP.']);
    exit;
}

if (time() > $_SESSION['reset_otp_expiry']) {
    unset($_SESSION['reset_otp'], $_SESSION['reset_username'], $_SESSION['reset_input'], $_SESSION['reset_otp_expiry']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

if ($_SESSION['reset_input'] !== $username || (string)$_SESSION['reset_otp'] !== (string)$otp) {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP.']);
    exit;
}

$real_username = $_SESSION['reset_username'];

require_once 'config.php';

try {
    $tables = ['students' => 'zprn', 'faculty' => 'username', 'hod' => 'username', 'admin' => 'username'];
    $updated = false;
    
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    foreach ($tables as $table => $column) {
        $stmt = $pdo->prepare("SELECT id FROM $table WHERE BINARY $column = ?");
        $stmt->execute([$real_username]);
        if ($stmt->fetch()) {
            $updateStmt = $pdo->prepare("UPDATE $table SET password = ? WHERE BINARY $column = ?");
            $updateStmt->execute([$password_hash, $real_username]);
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Clear OTP after successful reset
    unset($_SESSION['reset_otp'], $_SESSION['reset_username'], $_SESSION['reset_input'], $_SESSION['reset_otp_expiry']);

    echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
