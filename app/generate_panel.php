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

/*
 * Image of an observation. This endpoint used to build a "panel" (photo, map, texts);
 * it now serves the photo itself, with the same parameters and the same rules:
 * pixelated until a moderator approves it, except for its author (secretid) and for
 * admins/moderators (key). "s" is the requested width (max 1024).
 */

$error_prefix = 'GENERATE_PANEL';
$cwd          = dirname(__FILE__);

require_once("$cwd/includes/common.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header("Content-type: image/jpeg");

require_once("$cwd/includes/functions.php");
require_once("$cwd/includes/images.php");
require_once("$cwd/includes/handle.php");

$caches_path  = "$cwd" . '/' . $config['DATA_PATH'] . "caches/";
$images_path  = "$cwd" . '/' . $config['DATA_PATH'] . "images/";
$MAX_IMG_SIZE = 1024; // For limit attack
$resize_width = $MAX_IMG_SIZE; // default width

if (isset($_GET['key'])) {
    $key = $_GET['key'];
} else {
    $key = Null;
}

/* Token is mandatory */
if (!isset($_GET['token'])) {
    jsonError($error_prefix, "Token :  not provided.", "TOKENNOTPROVIDED", 400);
}

$token = mysqli_real_escape_string($db, $_GET['token']);

if (isset($_GET['secretid'])) {
    $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
} else {
    $secretid = Null;
}

if (isset($_GET["s"]) && is_numeric($_GET["s"]) && intval($_GET["s"]) > 0 && intval($_GET["s"]) <= $MAX_IMG_SIZE) {
    $resize_width = intval($_GET["s"]);
}

# The cache only holds the public rendering (pixelated until approved).
# "_p3" prefix: ignore the panels cached by older versions.
$img_filename = $caches_path . $token . '_p3_w' . $resize_width . '.jpg';

$role       = getrole($key, $acls);
$privileged = ($role == "admin" || $role == "moderator");

## Use caches if available (public rendering only)
if (!$privileged && $secretid === Null && file_exists($img_filename)) {
    readfile($img_filename);
    return;
}

# Get issue information
$query = mysqli_query($db, "SELECT obs_token, obs_approved, obs_secretid FROM obs_list WHERE obs_token = '$token' LIMIT 1");

if (mysqli_num_rows($query) != 1) {
    jsonError($error_prefix, "Token : " . $token . " not found.", "TOKENNOTFOUND", 404);
}

$result        = mysqli_fetch_array($query);
$approved      = $result['obs_approved'];
$AdminOrAuthor = ($privileged || ($secretid !== Null && $secretid === $result['obs_secretid']));

$filepath = $images_path . basename($token) . '.jpg';
$found    = file_exists($filepath);
if (!$found) {
    $filepath = "$cwd/style/image_404.jpg";
}

# Photo is pixelated until approved by a moderator, whatever the requested size
if ($found && $approved != 1 && !$AdminOrAuthor) {
    $image = pixalize($filepath);
} else {
    $image = @imagecreatefromjpeg($filepath);
}

if (!$image) {
    jsonError($error_prefix, "Image for : " . $token . " can not be read.", "IMAGENOTREADABLE", 500);
}

if (imagesx($image) > $resize_width || imagesy($image) > $MAX_IMG_SIZE) {
    $resized = resizeImage($image, $resize_width, $MAX_IMG_SIZE);
    if ($resized !== false) {
        $image = $resized;
    }
}

# Never cache a rendering made for an admin or the author: it may not be pixelated
if (!$AdminOrAuthor && $found) {
    imagejpeg($image, $img_filename, 90);
}
imagejpeg($image, null, 90);
