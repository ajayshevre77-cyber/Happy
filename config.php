<?php
// config.php
$db_host = 'localhost';
$db_name = 'erp_system';
$db_user = 'root';
$db_pass = ''; // Leave empty for default XAMPP setup

// SMTP Configuration
$smtp_host = 'smtp.gmail.com'; // Gmail SMTP server
$smtp_port = 587; // TLS port
$smtp_user = 'ajayshevre77@gmail.com'; // Replace with your Gmail address
$smtp_pass = 'yuar uajc fibp qnxc'; // Your 16-character App Password
$smtp_from = 'ajayshevre77@gmail.com'; // Replace with your Gmail address
$smtp_from_name = 'College ERP';

try {
    // Attempt to connect to the specific database
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // If the database doesn't exist, we might be running setup_db.php.
    // Allow connection without db_name to create it.
    try {
        $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // If we are not running setup_db.php, we should stop and prompt the user to initialize the database.
        if (basename($_SERVER['PHP_SELF']) !== 'setup_db.php') {
            die("<div style='font-family: sans-serif; padding: 2rem; text-align: center;'><h2>Database Not Initialized</h2><p>The database '<strong>$db_name</strong>' does not exist.</p><p>Please run the database setup script to initialize it: <a href='setup_db.php' style='color: #4f46e5; font-weight: bold;'>Run setup_db.php</a></p></div>");
        }
    } catch(PDOException $ex) {
        die("Database connection failed: " . $ex->getMessage());
    }
}
?>



