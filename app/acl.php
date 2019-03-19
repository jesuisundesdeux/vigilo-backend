<?php
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

require_once('./functions.php');

$status = 0;
$role = Null;

if(isset($_GET['key'])) {
  $key=$_GET['key'];
  $role = getrole($key, $acls);
} else{
  error_log('Private key not provided');
  $status = 1;
}


if($status != 0) {
  http_response_code(500);
}

echo json_encode(array('role'=>$role,'status'=>$status));

