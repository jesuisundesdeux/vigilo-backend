<?php
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

ini_set('max_input_vars', '3000');

$status = 0;

if (isset($_GET['token']) && isset($_GET['secretid'])) {
  $token = $_GET['token'];
  $secretid = $_GET['secretid'];
  $token = mysqli_real_escape_string($db, $token);
  $secretid = mysqli_real_escape_string($db, $secretid);
} else {
  error_log("ADD_IMAGE : Missing token and/or secretid parameters.");
  http_response_code(500);
  echo json_encode(array('status'=>1));
  return;
}

$checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");

if (mysqli_num_rows($checktoken_query) != 1) {
  error_log("ADD_IMAGE : Token : ".$token." and/or secretid : ".$secretid." do not exist.");
  http_response_code(500);
  echo json_encode(array('status'=>1));
  return;
}

$data = file_get_contents('php://input');

$filename = preg_replace('/[^A-Za-z0-9]/', '', $token);
$filepath = 'images/'.$filename.'.jpg';

if (!(file_put_contents($filepath, $data))) {
  error_log('ADD_IMAGE : Error uploading image');
  $status = 1;
}
else {
  $allowedTypes = array(IMAGETYPE_JPEG);
  $detectedType = exif_imagetype($filepath);
  if (!array($detectedType, $allowedTypes)) {
    unlink($filepath);
    error_log('ADD_IMAGE : File type not supported : '. $detectedType);
    $status = 1;
  }
  else {
    mysqli_query($db,"UPDATE obs_list SET obs_complete=1 WHERE obs_token='".$token."' AND obs_secretid='".$secretid."'");
  }
}

if ($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
