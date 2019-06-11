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

if(isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = NULL;
}

if(isset($_GET['secretid'])) {
  $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
}
else {
  $secretid= 0;
}

$status = 0;

$token = mysqli_real_escape_string($db,$_GET['token'] );

if(isset($_POST['comment']) {
  $comment = removeEmoji(mysqli_real_escape_string($db, $_POST['comment']));
  $comment = substr($comment,0,50);
}
else {
  $comment = '';
}

if(isset($_POST['time'])  && is_numeric($_POST['time'])) {
  $time = $_POST['time'];

  /* If time is provided in ms */
  if(strlen($time) == 14) {
    $time = floor($time / 1000);
  }
}
else {
  $time = time();
}

/* Moving client apps to POST method */
if(isset($_POST['statusobs']) && is_numeric($_POST['statusobs'])) {
  $statusobs = $_POST['statusobs'];
}
elseif(isset($_GET['statusobs']) && is_numeric($_GET['statusobs'])) {
  $statusobs = $_GET['statusobs'];
}
else {
  $statusobs= 0;
}

if(getrole($key, $acls) == "admin") {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
}
else {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");
}

if(mysqli_num_rows($checktoken_query) == 1) {
	mysqli_query($db,"UPDATE obs_list SET obs_status='".$statusobs."',
		                              obs_status_resolved_comment='".$comment."',
					      obs_status_resolved_time='".$time."'
	                  WHERE obs_token='".$token."' LIMIT 1");
  delete_token_cache($token);
}
else {
    error_log("UPDATE_STATUS : Token : ".$token." and/or secretid : ".$secretid." do not exist.");
    $status = 1;

}

if($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
