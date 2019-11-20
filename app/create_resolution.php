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

/* Deux tables à créer */
/*
obs_resolutions
* resolution_id
* resolution_token
* resolution_secretid
* resolution_app_version
* resolution_comment
* resolution_time


obs_resolutions_tokens
* restok_resolutionid
* restok_observationid
*/

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");
require_once("${cwd}/includes/functions.php");

header('BACKEND_VERSION: '.BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$error_prefix = 'CREATE_RESOLUTION';

if (isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = Null;
}

# First we need to know if it's an new issue or an update from an admin

$update = 0;

# Check if token exists
if (isset($_POST['rtoken']) AND !empty($_POST['rtoken'])) {
  $rtoken = mysqli_real_escape_string($db, $_POST['rtoken']);
  if (getrole($key, $acls) == "admin" OR getrole($key, $acls) == "moderator") {
    # Do the query only if it's an admin
    $query_rtoken = mysqli_query($db, "SELECT * FROM obs_resolutions WHERE resolution_token='".$rtoken."' LIMIT 1");
    # If token exists and the request is from an admin : We consider it as an update
    if (mysqli_num_rows($query_rtoken) == 1) {
//      delete_map_cache($rtoken);
//      delete_token_cache($rtoken);
      $result_irtoken = mysqli_fetch_array($query_rtoken);
      $secretid = $result_rtoken['resolution_secretid'];
      $update = 1;
    }
  }
}

# In all other cases generate secret and token
if (!$update) {
  # Generate a unique ID
  $secretid = str_replace('.', '', uniqid('', true));

  # Generate a unique token
  $rtoken = 'R_'.tokenGenerator(4);
}

$json = array('rtoken' => $rtoken, 'status' => 0, 'secretid' => $secretid);

# Handle mandatory fields
# Even if an admin is updating the observation, is has to send again all
# information anyway. Then, send an error if parameters are missing too.
if (!isset($_POST['tokenlist']) ||
    !isset($_POST['time']) ||
    !isset($_POST['scope'])) {
  jsonError($error_prefix, "Missing parameters", "PARAMNOTDEFINED", 400);
}

$tokenlist = mysqli_real_escape_string($db, $_POST['tokenlist']);
$tokenlist = explode(',',$tokenlist);

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

if (isset($_POST['version'])) {
  $version = (isset($_POST['version']) ? mysqli_real_escape_string($db, $_POST['version']) : 0);
} else {
  $version = Null;
}

if ($update) {
  mysqli_query($db, 'UPDATE obs_resolutions SET resolution_comment="'.$comment.'",
                                                resolution_time="'.$time.'"
                     WHERE resolution_token="'.$rtoken.'"');
} else {
  mysqli_query($db, 'INSERT INTO obs_resolutions (
                                  `resolution_rtoken`,
                                  `resolution_secretid`,
                                  `resolution_app_version`,
                                  `resolution_comment`,
                                  `resolution_time`);
                           VALUES (
                               "'.$rtoken.'",
                               "'.$secretid.'",
                               "'.$version.'",
                               "'.$comment.'",
                               "'.$time.'")');
  $resolution_id = mysqli_insert_id($db);
  foreach($tokenlist as $tokenuniq) {
    $token_id = getIdByToken($db,$tokenuniq);
    mysqli_query($db, "INSERT INTO obs_resolutions_tokens (`restok_resolutionid`,`restok_observationid`) VALUES ('".$resolution_id."','".$token_id."')"); 
  }
}

if ($mysqlerror = mysqli_error($db)) {
  jsonError($error_prefix, "Could not insert field", "MYSQLERROR", 500);
}

echo json_encode($json);
?>
