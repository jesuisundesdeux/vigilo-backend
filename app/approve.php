<?php
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

require_once('./functions.php');
$token = $_GET['token'];

if (isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = NULL;
}

$status = 0;
$token = mysqli_real_escape_string($db, $token);

/* Only admin can approve an observation */
if (getrole($key, $acls) != "admin") {
  error_log("ADD_IMAGE : Token : ".$token." and/or key not valid.");
  http_response_code(500);
  echo json_encode(array('status'=>1));
  return;
}

/* Check existence of token database */
$checktoken_query = mysqli_query($db,"SELECT obs_token,obs_comment,obs_time,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
if (mysqli_num_rows($checktoken_query) != 1) {
  error_log("APPROVE : Token : ".$token." does not exist.");
  http_response_code(500);
  echo json_encode(array('status'=>1));
  return;
}

/* Set the observation to approved */
$query = mysqli_query($db, "UPDATE obs_list set obs_approved=1 WHERE obs_token='" . $token . "'");

/* Now remove the cache for this observation to remove blurring */
delete_token_cache($token);

$checktoken_result = mysqli_fetch_array($checktoken_query);
$comment = $checktoken_result['obs_comment'];
$time = $checktoken_result['obs_time'];
$coordinates_lat = $checktoken_result['obs_coordinates_lat'];
$coordinates_lon = $checktoken_result['obs_coordinates_lon'];

/* Don't tweet observations if they are more than one-day old */
if ($time > (time() - 3600 * 24)) {
  $tweet_content = str_replace('[COMMENT]', $comment, $tweet_content);
  $tweet_content = str_replace('[TOKEN]', $token, $tweet_content);
  $tweet_content = str_replace('[COORDINATES_LON]', $coordinates_lon, $tweet_content);
  $tweet_content = str_replace('[COORDINATES_LAT]',$coordinates_lat, $tweet_content);
  tweet($tweet_content, 'https://'.$_SERVER['SERVER_NAME'].'/generate_panel.php?token='.$token, $twitter_ids);
}

if ($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
