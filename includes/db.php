<?php
// prof/includes/db.php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = 'localhost';
$DB_USER = 'cselmasombe_admin';
$DB_PASS = 'na57k,ad-$h#';
$DB_NAME = 'cselmasombe_admin'; // <- à adapter

$con = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$con->set_charset('utf8mb4');
