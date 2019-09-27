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

$error_prefix = 'CREATE_ISSUE';

if (isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = Null;
}

# First we need to know if it's an new issue or an update from an admin

$update = 0;

# Check if token exists
if (isset($_POST['token']) AND !empty($_POST['token'])) {
  $token = mysqli_real_escape_string($db, $_POST['token']);
  if (getrole($key, $acls) == "admin") {
    # Do the query only if it's an admin
    $query_token = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
    # If token exists and the request is from an admin : We consider it as an update
    if (mysqli_num_rows($query_token) == 1) {
      delete_map_cache($token);
      delete_token_cache($token);
      $result_token = mysqli_fetch_array($query_token);
      $secretid = $result_token['obs_secretid'];
      $update = 1;
    }
  }
}

# In all other cases generate secret and token
if (!$update) {
  # Generate a unique ID
  $secretid = str_replace('.', '', uniqid('', true));

  # Generate a unique token
  $token = tokenGenerator(4);
}

$json = array('token' => $token, 'status' => 0, 'secretid' => $secretid);

# Handle mandatory fields
# Even if an admin is updating the observation, is has to send again all
# information anyway. Then, send an error if parameters are missing too.
if (!isset($_POST['coordinates_lat']) ||
    !isset($_POST['coordinates_lon']) ||
    !isset($_POST['categorie']) ||
    !isset($_POST['address']) ||
    !isset($_POST['time']) ||
    !isset($_POST['scope'])) {
  jsonError($error_prefix, "Missing parameters", "PARAMNOTDEFINED", 400);
}

$coordinates_lat = mysqli_real_escape_string($db, $_POST['coordinates_lat']);
$coordinates_lon = mysqli_real_escape_string($db, $_POST['coordinates_lon']);
$categorie = mysqli_real_escape_string($db, $_POST['categorie']);
$address = mysqli_real_escape_string($db, $_POST['address']);
$scope = (isset($_POST['scope']) ? mysqli_real_escape_string($db, $_POST['scope']) : 0);
$time = mysqli_real_escape_string($db, $_POST['time']);

if (empty($coordinates_lat) or empty($coordinates_lon) or
  empty($categorie) or empty($time) or empty($address)) {
  jsonError($error_prefix, "Empty field not supported", "PARAMEMPTY", 400);
}

# TODO : test time if value is too high or too low

/* If time is sent in ms */
if(strlen($time) == 13) {
  $time = floor($time / 1000);
}

# Handle optional fields
if (isset($_POST['comment'])) {
  $comment = removeEmoji(mysqli_real_escape_string($db, $_POST['comment']));
  $comment = substr($comment, 0, 50); # Max 50 char
} else {
  $comment = Null;
}

if (isset($_POST['explanation'])) {
  $explanation = (isset($_POST['explanation']) ? removeEmoji(mysqli_real_escape_string($db, $_POST['explanation'])) : '');
} else {
  $explanation = Null;
}

if (isset($_POST['version'])) {
  $version = (isset($_POST['version']) ? mysqli_real_escape_string($db, $_POST['version']) : 0);
} else {
  $version = Null;
}

# Check scope compliancy

$query_scope = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_name='".$scope."' LIMIT 1");
$result_scope = mysqli_fetch_array($query_scope);
if (!$result_scope) {
  # No result found for this scope
  jsonError($error_prefix, "Unknow scope", "UNKNOWSCOPE", 400);
}

$city_id = 0;
$city_name = '';
if (isset($_POST['cityid']) && is_numeric($_POST['cityid']))  {
  $city_id = $_POST['cityid'];
}
if (isset($_POST['cityname']) && !empty($_POST['cityname'])) {
  $city_name = mysqli_real_escape_string($db, $_POST['cityname']);
}

# Check if observation is located inside rectangle area of the scope
if (!($coordinates_lat >= $result_scope['scope_coordinate_lat_min'] &&
      $coordinates_lat <= $result_scope['scope_coordinate_lat_max'] &&
      $coordinates_lon >= $result_scope['scope_coordinate_lon_min'] &&
      $coordinates_lon <= $result_scope['scope_coordinate_lon_max'])) {
  # We are outside the area
  jsonError($error_prefix, "Coordinates out of range and not located in the scope '$scope'", "COORDINATESNOTALLOWED", 403);
}

# Get list of cities within the scope
if($city_id != 0) {
  $query_cities = mysqli_query($db, "SELECT * FROM obs_cities WHERE city_scope='".$result_scope['scope_id']."' AND city_id='".$city_id."'");
  if (mysqli_num_rows($query_cities) == 0) {
    # No city found, that's a problem
    jsonError($error_prefix, "No city found within the scope ".$result_scope['scope_id'], "CITYNOTFOUND", 200, "WARNING");
    $city_id = 0;
  }
}


if ($update) {
  mysqli_query($db, 'UPDATE obs_list SET obs_coordinates_lat="'.$coordinates_lat.'",
                                         obs_coordinates_lon="'.$coordinates_lon.'",
                                         obs_city="'.$city_id.'",
                                         obs_cityname="'.$city_name.'",
                                         obs_comment="'.$comment.'",
                                         obs_explanation="'.$explanation.'",
                                         obs_address_string="'.$address.'",
                                         obs_categorie="'.$categorie.'",
                                         obs_time="'.$time.'",
                                         obs_app_version="'.$version.'"
                    WHERE obs_token="'.$token.'" AND obs_secretid="'.$secretid.'"');
} else {
  mysqli_query($db, 'INSERT INTO obs_list (
                                  `obs_scope`,
                                  `obs_city`,
                                  `obs_cityname`,
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
                               "'.$city_id.'",
                               "'.$city_name.'",
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
}

if ($mysqlerror = mysqli_error($db)) {
  jsonError($error_prefix, "Could not insert field", "MYSQLERROR", 500);
}

# Return Token value
$json['group'] = 0; /// Legacy

echo json_encode($json);
?>
