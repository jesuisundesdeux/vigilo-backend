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

$error_prefix = "UPDATE_STATUS";

if(isset($_POST['token'])) {
  $token = mysqli_real_escape_string($db,$_POST['token']);
}
else {
  jsonError($error_prefix, "Token missing", "TOKENMISSING", 400);
}

if(isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = NULL;
}

$role = getrole($key, $acls);

if (isset($_POST['statusobs']) && is_numeric($_POST['statusobs'])) {
  $new_status = mysqli_real_escape_string($db, $_POST['statusobs']);
}
else {
  jsonError($error_prefix, "Status missing", "STATUSMISSING", 400);
}

if (!in_array($role,$status_list[$new_status]['roles']) && !in_array("all",$status_list[$new_status]['roles'])) {
  jsonError($error_prefix, "Status not authorized", "NOTAUTHORIZED", 403); 
}

/* Check existence of token and secretid */
$checktoken_query = mysqli_query($db,"SELECT obs_id,obs_token FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
if (mysqli_num_rows($checktoken_query) != 1) {
  jsonError($error_prefix, "Token : ".$token." do not exist.", "TOKENNOTEXIST", 400);
}
$checktoken_result = mysqli_fetch_array($checktoken_query);
$obsid = $checktoken_result['obs_id'];

$status_query = mysqli_query($db,"SELECT status_update_status FROM obs_status_update WHERE status_update_obsid  = '".$obsid."' ORDER BY status_update_id DESC LIMIT 1");

if ($status_result = mysqli_fetch_array($status_query)) {
  $current_status = $status_result['status_update_status'];
}
else {
  $current_status = 0;
}

if (!in_array($new_status,$status_list[$current_status]['nextstatus'])) {
  jsonError($error_prefix, "New status not authorized", "FORBIDDENNEWSTATUS", 403);
}

$comment = "";
$time = time();

if (isset($_POST['comment'])) {
  $comment = mysqli_real_escape_string($db, $_POST['comment']);
  $comment = substr($comment, 0, 255);
}

if (isset($_POST['time'])) {
  $time = mysqli_real_escape_string($db, $_POST['time']);
}

$role_query = mysqli_query($db,"SELECT role_id FROM obs_roles WHERE role_key = '".$key."'");

if ($role_result = mysqli_fetch_array($role_query)) {
  $role_id = $role_result['role_id'];
}
else {
  $role_id = 0;
}

mysqli_query($db,"INSERT INTO obs_status_update (status_update_obsid,status_update_status,status_update_comment,status_update_time,status_update_roleid)
			  VALUES('".$obsid."','".$new_status."','".$comment."','".$time."','".$role_id."')");

?>
