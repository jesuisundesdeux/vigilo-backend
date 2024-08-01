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
ini_set('memory_limit', '256M');


$error_prefix = 'GENERATE_PANEL';
$cwd          = dirname(__FILE__);

require_once("$cwd/includes/common.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header("Content-type: image/jpeg");

require_once("$cwd/includes/functions.php");
require_once("$cwd/includes/images.php");
require_once("$cwd/includes/handle.php");


$query      = mysqli_query($db, "SELECT * FROM obs_config
                            WHERE config_param='vigilo_panel'
                            LIMIT 1");
$result     = mysqli_fetch_array($query);
$panel_path = $result['config_value'];

if (file_exists('panels/' . $panel_path . '/panel.php')) {
    require_once('panels/' . $panel_path . '/panel.php');
} else {
    die('Panel not exists');
}

$caches_path     = "$cwd" . '/' . $config['DATA_PATH'] . "caches/";
$images_path     = "$cwd" . '/' . $config['DATA_PATH'] . "images/";
$maps_path       = "$cwd" . '/' . $config['DATA_PATH'] . "maps/";
$MAX_IMG_SIZE    = 1024; // For limit attack
$resize_width    = $MAX_IMG_SIZE; // default width

if (isset($_GET['key'])) {
    $key = $_GET['key'];
} else {
    $key = Null;
}

/* Token is mandatory */
if (!isset($_GET['token'])) {
    jsonError($error_prefix, "Token : " . $token . " not provided.", "TOKENNOTPROVIDED", 400);
}

$token = mysqli_real_escape_string($db, $_GET['token']);

if (isset($_GET['secretid'])) {
    $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
} else {
    $secretid = Null;
}

if (isset($_GET["s"]) && is_numeric($_GET["s"]) && intval($_GET["s"]) <= $MAX_IMG_SIZE) {
    $resize_width = intval($_GET["s"]);
    $img_filename = $caches_path . $token . '_w' . $resize_width . '.jpg';
} else {
    $img_filename = $caches_path . $token . '_full.jpg';
}

## Use caches if available
if (file_exists($img_filename) && !getrole($key, $acls) == "admin" && !getrole($key, $acls) == "moderator") {
    $image = imagecreatefromjpeg($img_filename);
    imagejpeg($image);
    return;
}

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");

if (mysqli_num_rows($query) != 1) {
    jsonError($error_prefix, "Token : " . $token . " not found.", "TOKENNOTFOUND", 404);
}

$result            = mysqli_fetch_array($query);
$coordinates_lat   = $result['obs_coordinates_lat'];
$coordinates_lon   = $result['obs_coordinates_lon'];
$street_name       = $result['obs_address_string'];
$comment           = $result['obs_comment'];
$categorie_id      = $result['obs_categorie'];
$resolution_status = getResolutionStatus($result['obs_id']);
$categorie_string  = getCategorieName($categorie_id);

if (!empty($result['obs_city']) && $result['obs_city'] != 0) {
    $cityquery  = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_id='" . $result['obs_city'] . "' LIMIT 1");
    $cityresult = mysqli_fetch_array($cityquery);
    $street_name .= ', ' . $cityresult['city_name'];
} elseif (!empty($result['obs_cityname'])) {
    $street_name .= ', ' . $result['obs_cityname'];
}

if (!isset($categorie_string)) {
    jsonError($error_prefix, "unknown categorie_id: ' . $categorie_id", "UNKNOWCATEGORIE", 500);
}

$time = $result['obs_time'];
$date = date('d/m/Y H:i', $time);

$approved = $result['obs_approved'];
if ($secretid == $result['obs_secretid'] || getrole($key, $acls) == "admin" || getrole($key, $acls) == "moderator") {
    $AdminOrAuthor = True;
} else {
    $AdminOrAuthor = False;
}

$filepath = $images_path . $token . '.jpg';

# Image is pixelated until approved by a moderator
if ($approved != 1 && !$AdminOrAuthor && $resize_width > 300) {
    $photo = pixalize($filepath);
} else {
    $photo = imagecreatefromjpeg($filepath); // issue photo
}

$map_file_path = $maps_path . $token . '_zoom.jpg';
$res = GenerateMapQuestForToken($token, $map_file_path, $config['MAPQUEST_API']);
if (!$res) {
    // Use default place holder picture instead of crashing
    $map_file_path = implode(DIRECTORY_SEPARATOR, [$cwd, 'panels', $panel_path, 'panel_components', 'map_error.jpeg']);
}

$map = imagecreatefromjpeg($map_file_path);

if (!$map) {
    jsonError($error_prefix, "Map for : " . $token . " can not be created.", "MAPNOTCREATED", 500);
}

$image = GeneratePanel($photo, $map, $comment, $street_name, $token, $categorie_string, $date, $statusobs);

# Generate full size image
if ($AdminOrAuthor && $resize_width == $MAX_IMG_SIZE) {
    imagejpeg($image);
} else if ($resize_width == $MAX_IMG_SIZE) {
    # Use user original image
    imagejpeg($image, $img_filename);
    imagejpeg($image);
} else {
    $imageresized = resizeImage($image, $resize_width, $MAX_IMG_SIZE);
    imagejpeg($imageresized, $img_filename);
    imagejpeg($imageresized);
}
