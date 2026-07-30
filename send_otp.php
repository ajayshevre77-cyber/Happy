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
        $stmt = $pdo->prepare("SELECT zprn as username, email FROM students WHERE BINARY zprn = ? OR email = ?");
    } elseif ($role === 'faculty') {
        $stmt = $pdo->prepare("SELECT username, email FROM faculty WHERE BINARY username = ? OR email = ?");
    } elseif ($role === 'hod') {
        $stmt = $pdo->prepare("SELECT username, email FROM hod WHERE BINARY username = ? OR email = ?");
    } elseif ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT username, email FROM admin WHERE BINARY username = ? OR email = ?");
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid role.']);
        exit;
    }

    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    $real_username = $user['username'] ?? '';
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
    $_SESSION['reset_username'] = $real_username;
    $_SESSION['reset_input'] = $username;
    $_SESSION['reset_otp_expiry'] = time() + 300; // 5 minutes expiry
    
    // Send email using PHPMailer
    require_once 'vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtp_host ?? 'smtp.example.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_user ?? 'your_email@example.com';
        $mail->Password   = $smtp_pass ?? 'your_app_password';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp_port ?? 587;

        // Recipients
        $from_email = $smtp_from ?? 'noreply@example.com';
        $from_name  = $smtp_from_name ?? 'ERP System';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($email);

        // Content
        $mail->isHTML(false);
        $mail->Subject = 'Password Reset OTP';
        $mail->Body    = "Your OTP for password reset is: $otp\r\nThis OTP is valid for 5 minutes.";

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully to registered email address.']);
    } catch (Exception $e) {
        error_log("Failed to send OTP email to $email. Mailer Error: {$mail->ErrorInfo}");
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please check SMTP configuration.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
