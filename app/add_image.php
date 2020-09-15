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
require_once("${cwd}/includes/handle.php");

header('BACKEND_VERSION: '.BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
  exit;
}

ini_set('max_input_vars', '3000');
$error_prefix = 'ADD_IMAGE';

if (!isset($_GET['token']) || !isset($_GET['secretid'])) {
  jsonError($error_prefix, "Missing token and/or secretid parameters.", "MISSINGARGUMENT", 400);
}

if (isset($_GET['type'])) {
  $type = $_GET['type'];
}
else {
  $type = "obs";
}

$token = $_GET['token'];
$secretid = $_GET['secretid'];

if(isset($_GET['method']) && !empty($_GET['method'])) {
  $method = $_GET['method'];
}
else {
  $method = 'stdin';
}

$token = mysqli_real_escape_string($db, $token);
$secretid = mysqli_real_escape_string($db, $secretid);


if ($type == "obs") {
  $filename = preg_replace('/[^A-Za-z0-9]/', '', $token);
  $filepath = $config['DATA_PATH'] . 'images/'.$filename.'.jpg';

  if(!isTokenWithSecretId($db,$token,$secretid)) {
    jsonError($error_prefix, "Token : ".$token." and/or secretid : ".$secretid." do not exist.", "TOKENNOTEXIST", 400);
  }
}
elseif ($type == "resolution") {
  $filename = preg_replace('/[^A-Za-z0-9_]/', '', $token);
  $filepath = $config['DATA_PATH'] . 'images/resolutions/'.$filename.'.jpg';
 
  if(!file_exists($config['DATA_PATH'] . 'images/resolutions/')) {
    mkdir($config['DATA_PATH'] . 'images/resolutions/');
  }
  

  if(!isResolutionTokenWithSecretId($db,$token,$secretid)) {
    jsonError($error_prefix, "ResolutionToken : ".$token." and/or secretid : ".$secretid." do not exist.", "RESOLTOKENNOTEXIST", 400);
  }


} else {
  jsonError($error_prefix, "Missing token and/or secretid parameters.", "MISSINGARGUMENT", 400);
}

/* Save image */
$image_written = False;

if($method == 'base64') {
  $data = $_POST['imagebin64'];
  $image_content = base64_decode(str_replace(array('-', '_',' ','\n'), array('+', '/','+',' '), $data));
  $fd_image = fopen($filepath, "wb");
  $image_written = fwrite($fd_image, $image_content);
  fclose($fd_image);

} else {
  $data = file_get_contents("php://stdin");
  if (!(file_put_contents($filepath, $data))) {
    $data = file_get_contents('php://input');
    if (!(file_put_contents($filepath, $data))) {
      jsonError($error_prefix, "Error uploading image with input", "IMAGEUPLOADFAILED", 500);
    }
    else {
      $image_written = True;
    }
  }
  else {
    $image_written = True;
  }
}

if($image_written) {
  $allowedTypes = array(IMAGETYPE_JPEG);
  $detectedType = exif_imagetype($filepath);
  if (!array($detectedType, $allowedTypes)) {
    unlink($filepath);
    jsonError($error_prefix, 'File type not supported : '. $detectedType, "FILETYPENOTSUPPORTED", 400);
  }
  elseif (!isGoodImage($filepath)) {
    jsonError($error_prefix, 'File is corrupted', 'FILECORRUPTED', 500);
  }
  else {
    if ($type == "obs") {
      $obsid = getObsIdByToken($db,$token);
      mysqli_query($db,"UPDATE obs_list SET obs_complete=1 WHERE obs_id='".$obsid."'");
    }
    elseif ($type == "resolution") {
      $resolutionid =  getResolutionIdByResolutionToken($db,$token);
      mysqli_query($db,"UPDATE obs_resolutions SET resolution_complete=1,resolution_withphoto=1 WHERE resolution_id='".$resolutionid."'");
    }
  }
}

echo json_encode(array('status'=>0));
// status deprecated and replaced by http code (stays here for old apps)

?>
