<?php
/* Temp debug — DELETE after use */
if(($_GET['k']??'')!=='x9z'){ http_response_code(403); die(); }
require_once __DIR__.'/../config/db.php';
header('Content-Type: text/plain');

$r = $conn->query("SHOW COLUMNS FROM donations");
if(!$r){ die("TABLE MISSING: ".$conn->error); }
while($row=$r->fetch_assoc()) echo $row['Field'].' | '.$row['Type'].' | NULL='.$row['Null'].' | Default='.($row['Default']??'none')."\n";
echo "\n--- TEST INSERT ---\n";
$t = $conn->query("INSERT INTO donations (donor_email,category,quantity,pickup_address,contact,status,notes,priority,cloth_type,is_clean,working_status,created_at) VALUES ('t@t.com','other','1','Addr','9999999999','pending','','medium','',1,'working',NOW())");
echo $t ? "OK id=".$conn->insert_id : "FAIL: ".$conn->error;
$conn->query("DELETE FROM donations WHERE donor_email='t@t.com'");
