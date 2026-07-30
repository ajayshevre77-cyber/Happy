<?php
require_once 'config.php';
$stmt = $pdo->prepare("SELECT zprn FROM students WHERE zprn = '125UIT1195'");
$stmt->execute();
$res = $stmt->fetch();
if ($res) {
    echo "Found in MySQL: " . $res['zprn'] . "\n";
} else {
    echo "Not found in MySQL\n";
}
?>
