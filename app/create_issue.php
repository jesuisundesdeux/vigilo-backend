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

# Generate Unique ID
$secretid=str_replace('.', '', uniqid('', true));

if (isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = Null;
}

# Get Web form datas

$coordinates_lat = mysqli_real_escape_string($db, $_POST['coordinates_lat']);
$coordinates_lon = mysqli_real_escape_string($db, $_POST['coordinates_lon']);

/* Handle comment content */
$comment = removeEmoji(mysqli_real_escape_string($db, $_POST['comment']));
$comment = substr($comment,0,50);

$categorie = mysqli_real_escape_string($db, $_POST['categorie']);
$address = mysqli_real_escape_string($db, $_POST['address']);
$time = mysqli_real_escape_string($db, $_POST['time']);
$time = floor($time / 1000);
$explanation = (isset($_POST['explanation']) ? removeEmoji(mysqli_real_escape_string($db, $_POST['explanation'])) : '');
$version = (isset($_POST['version']) ? mysqli_real_escape_string($db, $_POST['version']) : 0);
$scope = (isset($_POST['scope']) ? mysqli_real_escape_string($db, $_POST['scope']) : 0);
$status = 0;

# Check scope compliancy
$query_scope = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_name='".$scope."' LIMIT 1");
$result_scope = mysqli_fetch_array($query_scope);
if($coordinates_lat >= $result_scope['scope_coordinate_lat_min'] &&
   $coordinates_lat <= $result_scope['scope_coordinate_lat_max'] &&
   $coordinates_lon >= $result_scope['scope_coordinate_lon_min'] &&
   $coordinates_lon <= $result_scope['scope_coordinate_lon_max']) {
  
  # Check if token exist
  #
  if(isset($_POST['token']) AND !empty($_POST['token'])) {
    $token = mysqli_real_escape_string($db, $_POST['token']);
  }
  else {
    $token = tokenGenerator(4);
  }

  $query_token = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token='".$token."' LIMIT 1");

  /* If token exists and the request is from an admin : We consider it as an update */
  if (mysqli_num_rows($query_token) == 1 && getrole($key, $acls) == "admin") {
    delete_map_cache($token);
    delete_token_cache($token);
    $result_token = mysqli_fetch_array($query_token);
    $secretid = $result_token['obs_secretid'];
    $json = array('token' => $token, 'status' => 0, 'secretid'=>$secretid);
    mysqli_query($db,'UPDATE obs_list SET obs_coordinates_lat="'.$coordinates_lat.'",
                                          obs_coordinates_lon="'.$coordinates_lon.'",
                                          obs_comment="'.$comment.'",
                                          obs_explanation="'.$explanation.'",
                                          obs_address_string="'.$address.'",
                                          obs_categorie="'.$categorie.'",
                                          obs_time="'.$time.'",
                                          obs_app_version="'.$version.'"
                      WHERE obs_token="'.$token.'" AND obs_secretid="'.$secretid.'"');
  }
  else {
  
    if (mysqli_num_rows($query_token) == 1 or empty($token)) {
      $token = tokenGenerator(4);
    }
  
    # Init Datas
    $json = array('token' => $token, 'status' => 0, 'secretid' => $secretid);
  
    # Insert user datas to MySQL Database
    if (!empty($coordinates_lat) and !empty($coordinates_lon) and !empty($categorie) and !empty($time) and !empty($address)) {
      mysqli_query($db, 'INSERT INTO obs_list (
                                      `obs_scope`,
                                      `obs_coordinates_lat`,
                                      `obs_coordinates_lon`,
                                      `obs_address_string`,
                                      `obs_comment`,
                                      `obs_explanation`,
                                      `obs_categorie`,
                                      `obs_token`,
                                      `obs_time`,
                                      `obs_status`,
                                      `obs_app_version`,
                                      `obs_secretid`) 
                               VALUES (
                                   "'.$scope.'",
                                   "'.$coordinates_lat.'",
                                   "'.$coordinates_lon.'",
                                   "'.$address.'",
                                   "'.$comment.'",
                                   "'.$explanation.'",
                                   "'.$categorie.'",
                                   "'.$token.'",
                                   "'.$time.'",
                                   0,
                                   "'.$version.'",
                                   "'.$secretid.'")');
          
      if ($mysqlerror = mysqli_error($db)) {
        $status = 1;
        $error_code = "Could not insert field";
        error_log('CREATE_ISSUE : MySQL Error '.$mysqlerror);
      }
    }
    else {
      $status = 1;
      $error_code = "Empty field not supported";
      error_log('CREATE_ISSUE : Field not supported');
    }
  }  
}
else {
  $status = 1;
  $error_code = "Coordinates out of range in the scope";
  error_log('CREATE ISSUE' . $error_code);
}
# If error force return 500 ERROR CODE
if ($status != 0) {
  http_response_code(500);
  $json['error_code'] = $error_code;
}

# Return Token value
$json['status'] = $status;
$json['group'] = 0;
echo json_encode($json);
?>
