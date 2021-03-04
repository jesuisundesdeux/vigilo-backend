<?php
/*
Copyright (C) 2020 Velocité Montpellier

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
require_once("${cwd}/includes/handle.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$error_prefix = 'CREATE_RESOLUTION';

if (isset($_GET['key'])) {
    $key = $_GET['key'];
} else {
    $key = Null;
}

$update = 0;

# Check if token exists
if (isset($_POST['token']) AND !empty($_POST['token'])) {
    $token = mysqli_real_escape_string($db, $_POST['token']);
}

# In all other cases generate secret and token
# FIXME : consider updates
if (!$update) {
    # Generate a unique ID
    $secretid = str_replace('.', '', uniqid('', true));
    
    # Generate a unique token
    $token = 'R_' . tokenGenerator(4);
}

$json = array(
    'token' => $token,
    'secretid' => $secretid
);

# Handle mandatory fields
# Even if an admin is updating the observation, is has to send again all
# information anyway. Then, send an error if parameters are missing too.
if (!isset($_POST['tokenlist']) || !isset($_POST['time'])) {
    jsonError($error_prefix, "Missing parameters", "PARAMNOTDEFINED", 400);
}

$tokenlist = mysqli_real_escape_string($db, $_POST['tokenlist']);
$tokenlist = explode(',', $tokenlist);

$time      = mysqli_real_escape_string($db, $_POST['time']);

/* If time is sent in ms */
if (strlen($time) == 13) {
    $time = floor($time / 1000);
}

# Handle optional fields
if (isset($_POST['comment'])) {
    $comment = removeEmoji(mysqli_real_escape_string($db, $_POST['comment']));
    $comment = substr($comment, 0, 50); # Max 50 char
} else {
    $comment = Null;
}

if (isset($_POST['version'])) {
    $version = (isset($_POST['version']) ? mysqli_real_escape_string($db, $_POST['version']) : 0);
} else {
    $version = Null;
}

if ($update) {
    $fields        = array(
        'resolution_comment' => $comment,
        'resolution_time' => $time
    );
    $resolution_id = getResolutionIdByResolutionToken($db, $token);
    /* FIXME ADD UPDATE
    updateResolution($db,$fields,$resolution_id);
    */
} else {
    $fields = array(
        'resolution_token' => $token,
        'resolution_secretid' => $secretid,
        'resolution_app_version' => $version,
        'resolution_comment' => $comment,
        'resolution_time' => $time,
        'resolution_status' => 4
    );
    
    $obsidlist = array();
    foreach ($tokenlist as $token) {
        $obsidlist[] = getObsIdByToken($db, $token);
    }
    
    if (!addResolution($db, $fields, $obsidlist)) {
      jsonError($error_prefix, "Could not create resolution", "FUNCTIONERROR", 500);
    }
}

if ($mysqlerror = mysqli_error($db)) {
    jsonError($error_prefix, "Could not insert field", "MYSQLERROR", 500);
}

echo json_encode($json);
?>
