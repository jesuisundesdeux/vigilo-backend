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

require_once("{$cwd}/includes/common.php");
require_once("{$cwd}/includes/functions.php");
require_once("{$cwd}/includes/images.php");
require_once("{$cwd}/includes/blur.php");
require_once("{$cwd}/includes/handle.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}

ini_set('max_input_vars', '3000');
$MAX_IMG_SIZE = 1024; // For limit attack
$error_prefix = 'ADD_IMAGE';

if (!isset($_GET['token']) || !isset($_GET['secretid'])) {
   jsonError($error_prefix, "Missing token and/or secretid parameters.", "MISSINGARGUMENT", 400);
}

if (isset($_GET['type'])) {
    $type = $_GET['type'];
} else {
    $type = "obs";
}

$token    = $_GET['token'];
$secretid = $_GET['secretid'];

$blur_url = blur_server_url($db);

if (isset($_GET['method']) && !empty($_GET['method'])) {
    $method = $_GET['method'];
} else {
    $method = 'stdin';
}

$token    = mysqli_real_escape_string($db, $token);
$secretid = mysqli_real_escape_string($db, $secretid);


if (isset($_GET['key'])) {
    $key = $_GET['key'];
} else {
    $key = Null;
}
$privileged = (getrole($key, $acls) == "admin" || getrole($key, $acls) == "moderator");

if ($type == "obs") {
    $filename = preg_replace('/[^A-Za-z0-9]/', '', $token);
    $filepath = $config['DATA_PATH'] . 'images/' . $filename . '.jpg';
    
    if (!isTokenWithSecretId($token, $secretid)) {
        jsonError($error_prefix, "Token : " . $token . " and/or secretid : " . $secretid . " do not exist.", "TOKENNOTEXIST", 400);
    }

    // Once a moderator approved the observation, its photo can not be replaced by
    // its author anymore (it would be published without moderation)
    $approved_query = mysqli_query($db, "SELECT obs_approved FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
    $approved_row   = $approved_query ? mysqli_fetch_array($approved_query) : null;
    if ($approved_row && $approved_row['obs_approved'] == 1 && !$privileged) {
        jsonError($error_prefix, "Token : " . $token . " is already approved, its photo can not be replaced.", "ALREADYAPPROVED", 403);
    }
} elseif ($type == "resolution") {
    $filename = preg_replace('/[^A-Za-z0-9_]/', '', $token);
    $filepath = $config['DATA_PATH'] . 'images/resolutions/' . $filename . '.jpg';
    
    if (!file_exists($config['DATA_PATH'] . 'images/resolutions/')) {
        mkdir($config['DATA_PATH'] . 'images/resolutions/');
    }
    
    if (!isResolutionTokenWithSecretId($token, $secretid)) {
        jsonError($error_prefix, "ResolutionToken : " . $token . " and/or secretid : " . $secretid . " do not exist.", "RESOLTOKENNOTEXIST", 400);
    }
    
} else {
    jsonError($error_prefix, "Missing token and/or secretid parameters.", "MISSINGARGUMENT", 400);
}

/* Save the upload in a temporary file: the photo in place is only replaced by a valid image */
$final_filepath = $filepath;
$filepath       = $final_filepath . '.upload-' . bin2hex(random_bytes(4));
$image_written  = saveImageOnDisk($method, $filepath, $error_prefix);

if ($image_written) {
    if (!hasAllowedType($filepath)) {        
        // deepcode ignore PT: $filename is sanitized with preg_replace
        unlink($filepath);
        jsonError($error_prefix, 'File type not supported : ', "FILETYPENOTSUPPORTED", 400);
    } elseif (!isGoodImage($filepath)) {
        unlink($filepath);
        jsonError($error_prefix, 'File is corrupted', 'FILECORRUPTED', 500);
    } else {
        $image = imagecreatefromjpeg($filepath);
        $imageresized = resizeImage($image, $MAX_IMG_SIZE, $MAX_IMG_SIZE);
        if ($imageresized !== false) {
            imagejpeg($imageresized, $filepath);
        }

        // Optional blur server (faces, licence plates). If it fails, the photo is
        // kept as sent: the moderators check it before it is published.
        if ($blur_url !== '') {
            $blur_error = blur_photo($blur_url, $filepath);
            if ($blur_error !== null) {
                error_log('[WARNING] ' . $error_prefix . ': photo of ' . $token . ' not blurred - ' . $blur_error);
                header('X-Vigilo-Blur: failed');
            } else {
                header('X-Vigilo-Blur: done');
            }
        }
        rename($filepath, $final_filepath);
        $filepath = $final_filepath;
        if ($type == "obs") {
            delete_token_cache($token);
            $obsid = getObsIdByToken($token);
            mysqli_query($db, "UPDATE obs_list SET obs_complete=1 WHERE obs_id='" . $obsid . "'");
        } elseif ($type == "resolution") {
            $resolutionid = getResolutionIdByResolutionToken($token);
            mysqli_query($db, "UPDATE obs_resolutions SET resolution_complete=1,resolution_withphoto=1 WHERE resolution_id='" . $resolutionid . "'");
        }
    }
}

echo json_encode(array(
    'status' => 0
));
// status deprecated and replaced by http code (stays here for old apps)

?>
