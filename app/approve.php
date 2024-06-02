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

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$error_prefix = "APPROVE";
$token        = $_GET['token'];

if (isset($_GET['key'])) {
    $key = $_GET['key'];
} else {
    $key = NULL;
}

$status = 0;
$token  = mysqli_real_escape_string($db, $token);

/* Only admin and moderators can approve an observation */
if (getrole($key, $acls) != "admin" && getrole($key, $acls) != "moderator") {
    jsonError($error_prefix, "Unauthorized access.", "ACCESSDENIED", 403);
}

$approved = 1;
if (isset($_GET['approved']) and is_numeric($_GET['approved'])) {
    $approved = intval($_GET['approved']);
}

/* Check existence of token database */
$checktoken_query = mysqli_query($db, "SELECT obs_token,obs_scope,obs_comment,obs_time,obs_coordinates_lat,obs_coordinates_lon,obs_categorie,obs_city,obs_cityname,obs_address_string FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
if (mysqli_num_rows($checktoken_query) != 1) {
    jsonError($error_prefix, "Token : " . $token . " does not exist.", "TOKENNOTEXISTS", 400);
}

/* Set the observation to approved */
$query = mysqli_query($db, "UPDATE obs_list set obs_approved=" . $approved . " WHERE obs_token='" . $token . "'");

/* Now remove the cache for this observation to remove blurring or add */
delete_token_cache($token);

if ($approved == 1) {
	// on twitte l'observation
	// TODO: make sure we tweet or toot, depending on the configuration
	$r = tweetToken($token ) ;
	if ( $r['success'] == true ) {
		$i = 0 ; // do nothing
	}
	else {
		// TODO: rename TWITTERERROR to SOCIALERROR or such
		jsonError($error_prefix, $r['error'] , "TWITTERERROR", 200, "NOTICE");
	}
}

echo json_encode(array(
    'status' => '0'
));

?>
