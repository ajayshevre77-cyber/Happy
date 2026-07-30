<?php
require_once 'db.php';
$db = get_db();
foreach ($db['students'] as &$s) {
    if ($s['id'] === '125UIT1195') {
        $s['profile_details'] = ['test' => 'data'];
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
