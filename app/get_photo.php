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
header("Content-type: image/png");
header('Access-Control-Allow-Origin: *');

$token = $_GET['token'];

if(isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = NULL;
}

$status = 0;
$token = mysqli_real_escape_string($db, $token);
$checktoken_query = mysqli_query($db,"SELECT obs_token,obs_approved FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
if(mysqli_num_rows($checktoken_query) != 1) {
  error_log("GET_PHOTO : Token : ".$token." and/or key not valid.");
  $status = 1;
} 
else {
  $checktoken_result = mysqli_fetch_array($checktoken_query);	
  if(getrole($key, $acls) == "admin" || $checktoken_result['obs_approved'] == 1) {
    $photo = imagecreatefromjpeg('./images/' . $token . '.jpg'); // issue photo
    imagejpeg($photo); 
  }
  else {
    error_log("GET_PHOTO : Image is not allowed");
    $status = 1;
  }
}

if($status != 0) {
    http_response_code(500);
}
echo json_encode(array('status'=>$status));

?>
