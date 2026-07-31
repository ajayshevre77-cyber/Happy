<?php
$file = "student_dashboard.php";
$content = file_get_contents($file);

$start = strpos($content, "<div id=\"tab-profile\" class=\"app-view\">");
$end = strpos($content, "<!-- ============================================ -->", $start + 1);

if ($start !== false && $end !== false) {
    $profile_html = substr($content, $start, $end - $start);
    
    // Remove all asterisks
    $profile_html = str_replace("<span style=\"color:red;\">*</span>", "", $profile_html);
    
    // Remove all required attributes
    $profile_html = preg_replace("/\s+required(\s|>)/", "$1", $profile_html);
    
    // Now add them back for specific fields.
    // First Name
    $profile_html = str_replace("<label>First Name </label>", "<label>First Name <span style=\"color:red;\">*</span></label>", $profile_html);
    $profile_html = preg_replace("/name=\"first_name\"/", "name=\"first_name\" required", $profile_html, 1);
    
    // Middle Name
    $profile_html = str_replace("<label>Middle Name </label>", "<label>Middle Name <span style=\"color:red;\">*</span></label>", $profile_html);
    $profile_html = preg_replace("/name=\"middle_name\"/", "name=\"middle_name\" required", $profile_html, 1);
    
    // Last Name
    $profile_html = str_replace("<label>Last Name </label>", "<label>Last Name <span style=\"color:red;\">*</span></label>", $profile_html);
    $profile_html = preg_replace("/name=\"last_name\"/", "name=\"last_name\" required", $profile_html, 1);
    
    // Email(Official)
    $profile_html = str_replace("<label>Email(Official) </label>", "<label>Email(Official) <span style=\"color:red;\">*</span></label>", $profile_html);
    $profile_html = preg_replace("/name=\"official_email\"/", "name=\"official_email\" required", $profile_html, 1);
    
    // Mobile Number
    $profile_html = str_replace("<label>Mobile Number </label>", "<label>Mobile Number <span style=\"color:red;\">*</span></label>", $profile_html);
    $profile_html = preg_replace("/name=\"mobile_number\"/", "name=\"mobile_number\" required", $profile_html, 1);
    
    $content = substr_replace($content, $profile_html, $start, $end - $start);
    file_put_contents($file, $content);
    echo "Success!";
} else {
    echo "Section not found.";
}
?>
