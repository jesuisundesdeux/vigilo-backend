<?php
/*
Copyright (C) 2019 Velocité Montpellier

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
 any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");
require_once("${cwd}/includes/functions.php");

header('BACKEND_VERSION: '.BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
  error_log("APPROVE : Unauthorized access.");
  http_response_code(401);
  echo json_encode(array('status'=>1));
  return;
}

$approved = 1;
if (isset($_GET['approved']) and is_numeric($_GET['approved'])) {
  $approved = intval($_GET['approved']);
}

/* Check existence of token database */
$checktoken_query = mysqli_query($db,"SELECT obs_token,obs_scope,obs_comment,obs_time,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
if (mysqli_num_rows($checktoken_query) != 1) {
  error_log("APPROVE : Token : ".$token." does not exist.");
  http_response_code(500);
  echo json_encode(array('status'=>1));
  return;
}

/* Set the observation to approved */
$query = mysqli_query($db, "UPDATE obs_list set obs_approved=".$approved." WHERE obs_token='" . $token . "'");
  
/* Now remove the cache for this observation to remove blurring or add */
 delete_token_cache($token);

if($approved == 1) {
  
  $checktoken_result = mysqli_fetch_array($checktoken_query);
  $comment = $checktoken_result['obs_comment'];
  $time = $checktoken_result['obs_time'];
  $coordinates_lat = $checktoken_result['obs_coordinates_lat'];
  $coordinates_lon = $checktoken_result['obs_coordinates_lon'];
  $scope = $checktoken_result['obs_scope'];
  
  $scope_query = mysqli_query($db,"SELECT obs_scopes.scope_twitteraccountid,
                                          obs_scopes.scope_twittercontent,
                                          obs_twitteraccounts.ta_consumer,
                                          obs_twitteraccounts.ta_consumersecret,
                                          obs_twitteraccounts.ta_accesstoken,
                                          obs_twitteraccounts.ta_accesstokensecret  
                                   FROM obs_scopes, obs_twitteraccounts 
                                   WHERE obs_scopes.scope_twitteraccountid= obs_twitteraccounts.ta_id 
                                     AND obs_scopes.scope_name = '".$scope."'");
  $scope_result = mysqli_fetch_array($scope_query);
  if(!empty($scope_result['ta_consumer']) && !empty($scope_result['ta_consumersecret']) && !empty($scope_result['ta_accesstoken']) && !empty($scope_result['ta_accesstokensecret'])) {

    $twitter_ids =  array("consumer" => $scope_result['ta_consumer'], 
                     "consumersecret" => $scope_result['ta_consumersecret'], 
                     "accesstoken" => $scope_result['ta_accesstoken'], 
                     "accesstokensecret" => $scope_result['ta_accesstokensecret']);
    $tweet_content = $scope_result['scope_twittercontent'];

    /* Don't tweet observations if they are more than N-hours old */
    if ($time > (time() - 3600 * $config['APPROVE_TWITTER_EXPTIME'])) {
      $tweet_content = str_replace('[COMMENT]', $comment, $tweet_content);
      $tweet_content = str_replace('[TOKEN]', $token, $tweet_content);
      $tweet_content = str_replace('[COORDINATES_LON]', $coordinates_lon, $tweet_content);
      $tweet_content = str_replace('[COORDINATES_LAT]',$coordinates_lat, $tweet_content);
      tweet($tweet_content, $config['HTTP_PROTOCOL'].'://'.$_SERVER['SERVER_NAME'].'/generate_panel.php?token='.$token, $twitter_ids);
    } else {
      error_log("APPROVE : Token : ".$token." older than ".$config['APPROVE_TWITTER_EXPTIME']."h. We won't tweet it.");
    }
  }
  else {
    error_log("APPROVE : Empty Twitter informations on scope");
  }
}
if ($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
