<?php
require_once('./common.php');
$token = $_GET['token'];
$secretid = $_GET['secretid'];

$status = 0;
$token = mysqli_real_escape_string($db, $token);
$secretid = mysqli_real_escape_string($db, $secretid);
$checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");

if(mysqli_num_rows($checktoken_query) == 1) {
  mysqli_query($db,"DELETE FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");
  unlink('images/'.$token.'.jpg');
  unlink('caches/'.$token.'_full.png');
  unlink('maps/'.$token.'_zoom.jpg');
  unlink('maps/'.$token.'.jpg');
}
else {
    error_log("ADD_IMAGE : Token : ".$token." and/or secretid : ".$secretid." do not exist.");
    $status = 1;

}

if($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
