<?php
require_once('common.php');
$r = db_query_all("SELECT username, superadmin FROM admin ORDER BY username");
echo json_encode($r, JSON_PRETTY_PRINT);
