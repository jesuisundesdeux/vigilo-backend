<?php
header("Content-type: image/png");
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

require_once('./functions.php');
$token = $_GET['token'];

if(isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = NULL;
}

$status = 0;
$token = mysqli_real_escape_string($db, $token);

if(getrole($key, $acls) == "admin") {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    $photo = imagecreatefromjpeg('./images/' . $token . '.jpg'); // issue photo
    imagejpeg($photo); 
  }
}
else {
    error_log("ADD_IMAGE : Token : ".$token." and/or key not valid.");
    $status = 1;

}

if($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
