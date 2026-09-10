<?php
/**
 * Quick DB check — DELETE after use
 * URL: https://soulserves.onrender.com/database/check_db.php?key=check2026
 */
if(($_GET['key']??'') !== 'check2026'){ http_response_code(403); die('403'); }
require_once __DIR__ . '/../config/db.php';
header('Content-Type: text/plain');

// Show donations table columns
echo "=== DONATIONS TABLE ===\n";
$r = $conn->query("SHOW COLUMNS FROM donations");
if($r){ while($row=$r->fetch_assoc()) echo $row['Field']." | ".$row['Type']." | ".$row['Null']." | ".$row['Default']."\n"; }
else echo "ERROR: ".$conn->error."\n";

echo "\n=== TEST INSERT ===\n";
$test = $conn->query("INSERT INTO donations (donor_email,category,quantity,description,condition_type,pickup_address,contact,status,notes,priority,cloth_type,is_clean,working_status,created_at) VALUES ('test@test.com','other','1 item','Test','good','Test Address','9999999999','pending','','medium','',1,'working',NOW())");
if($test){ 
    echo "Insert OK! id=".$conn->insert_id."\n"; 
    $conn->query("DELETE FROM donations WHERE donor_email='test@test.com'");
} else {
    echo "Insert FAILED: ".$conn->error."\n";
}
