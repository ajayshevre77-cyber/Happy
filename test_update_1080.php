<?php
require_once 'db.php';
$db = get_db();
foreach ($db['students'] as &$s) {
    if ($s['id'] === '125UIT1080') {
        $s['profile_details']['test_field'] = 'test_value_123';
        break;
    }
}
try {
    save_db($db);
    echo "Saved OK.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
