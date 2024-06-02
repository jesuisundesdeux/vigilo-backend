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

if (isset($_GET['s']) && is_numeric($_GET['s'])) {
    $requested_size = intval($_GET['s']);
} else {
    $requested_size = Null;
}

require_once("$cwd/includes/functions.php");

$image = generate_and_save_panel($token, $resize_width, $secretid, $key, $error_prefix);

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
