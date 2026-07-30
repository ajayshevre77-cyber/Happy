<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$role = $_POST['role'] ?? 'student';

if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'Username is required to generate OTP.']);
    exit;
}

require_once 'config.php';

try {
    $user = null;
    $email = '';
    
    if ($role === 'student') {
        $stmt = $pdo->prepare("SELECT email FROM students WHERE BINARY zprn = ?");
    } elseif ($role === 'faculty') {
        $stmt = $pdo->prepare("SELECT email FROM faculty WHERE BINARY username = ?");
    } elseif ($role === 'hod') {
        $stmt = $pdo->prepare("SELECT email FROM hod WHERE BINARY username = ?");
    } elseif ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT email FROM admin WHERE BINARY username = ?");
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid role.']);
        exit;
    }

    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $email = $user['email'] ?? '';

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'No email registered for this user. Cannot send OTP.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Registered email is invalid.']);
        exit;
    }

    // Generate a secure 6-digit OTP
    $otp = (string)random_int(100000, 999999);
    
    // Store OTP securely in session
    $_SESSION['reset_otp'] = $otp;
    $_SESSION['reset_username'] = $username;
    $_SESSION['reset_otp_expiry'] = time() + 300; // 5 minutes expiry
    
    // Send email using built-in mail() function
    $subject = 'Password Reset OTP';
    $message = "Your OTP for password reset is: $otp\r\nThis OTP is valid for 5 minutes.";
    
    // Use the from address if defined in config, else default
    $from_email = $smtp_from ?? 'noreply@example.com';
    $headers = "From: $from_email\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // Suppress warnings in case mail server is not configured
    if (@mail($email, $subject, $message, $headers)) {
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully to registered email address.']);
    } else {
        error_log("Failed to send OTP email to $email using mail().");
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again later.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
