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
require_once("${cwd}/includes/images.php");
require_once("${cwd}/includes/handle.php");

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

$query      = mysqli_query($db, "SELECT * FROM obs_config
                            WHERE config_param='sgblur_url'
                            LIMIT 1");
$result     = mysqli_fetch_array($query);
$sgblur_url = $result['config_value'];

if (isset($_GET['method']) && !empty($_GET['method'])) {
    $method = $_GET['method'];
} else {
    $method = 'stdin';
}

$token    = mysqli_real_escape_string($db, $token);
$secretid = mysqli_real_escape_string($db, $secretid);


if ($type == "obs") {
    $filename = preg_replace('/[^A-Za-z0-9]/', '', $token);
    $filepath = $config['DATA_PATH'] . 'images/' . $filename . '.jpg';
    
    if (!isTokenWithSecretId($token, $secretid)) {
        jsonError($error_prefix, "Token : " . $token . " and/or secretid : " . $secretid . " do not exist.", "TOKENNOTEXIST", 400);
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

/* Save image */
$image_written = saveImageOnDisk($method, $filepath, $error_prefix);

if ($image_written) {
    if (!hasAllowedType($filepath)) {        
        // deepcode ignore PT: $filename is sanitized with preg_replace
        unlink($filepath);
        jsonError($error_prefix, 'File type not supported : ' . $detectedType, "FILETYPENOTSUPPORTED", 400);
    } elseif (!isGoodImage($filepath)) {
        jsonError($error_prefix, 'File is corrupted', 'FILECORRUPTED', 500);
    } else {
        $image = imagecreatefromjpeg($filepath);
        $imageresized = resizeImage($image, $MAX_IMG_SIZE, $MAX_IMG_SIZE);
        if ($imageresized !== false) {
            imagejpeg($imageresized, $filepath);
        }

        if($sgblur_url) {
            // Préparer le fichier à envoyer
            $cfile = curl_file_create($filepath, mime_content_type($filepath), basename($filepath));

            // Configurer la requête cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sgblur_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['picture' => $cfile]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Exécuter la requête
            $response = curl_exec($ch);

            // Vérifier les erreurs
             if (curl_errno($ch)) {
                jsonError($error_prefix, "Bluring issue : ".curl_error($ch), "SGBLURISSUE", 500);               
            } else {
            // Sauvegarder la réponse dans un fichier
                file_put_contents($filepath, $response);
            }

            // Fermer la session cURL
            curl_close($ch); 
        }
        if ($type == "obs") {
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
