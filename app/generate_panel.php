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
header("Content-type: image/jpeg");

$error_prefix = 'GENERATE_PANEL';

ini_set('memory_limit','256M');

$MAX_IMG_SIZE = 1024; // For limit attack
$resize_width = $MAX_IMG_SIZE; // default width
$PERCENT_PIXELATED = 5;

if (isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = Null;
}

/* Token is mandatory */
if (!isset($_GET['token'])) {
  jsonError($error_prefix, "Token : ".$token." not provided.", "TOKENNOTPROVIDED", 400);
}

$token = mysqli_real_escape_string($db, $_GET['token']);

if (isset($_GET['secretid'])) {
  $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
}
else {
  $secretid = Null;
}

if (isset($_GET["s"]) and is_numeric($_GET["s"]) and intval($_GET["s"]) <= $MAX_IMG_SIZE) {
  $resize_width = intval($_GET["s"]);
  $img_filename = './caches/' . $token . '_w' . $resize_width . '.jpg';
} else {
  $img_filename = './caches/' . $token . '_full.jpg';
}

## Use caches if available
if (file_exists($img_filename) AND !getrole($key, $acls) == "admin" AND !getrole($key, $acls) == "moderator") {
  $image = imagecreatefromjpeg($img_filename);
  imagejpeg($image);
  return;
}

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");

if (mysqli_num_rows($query) != 1) {
  jsonError($error_prefix, "Token : ".$token." not found.", "TOKENNOTFOUND", 404);
}

$result = mysqli_fetch_array($query);
$coordinates_lat = $result['obs_coordinates_lat'];
$coordinates_lon = $result['obs_coordinates_lon'];
$street_name = $result['obs_address_string'];
$comment = $result['obs_comment'];
$categorie_id = $result['obs_categorie'];
$statusobs = $result['obs_status'];
$categorie_string = getCategorieName($categorie_id);


if (!empty($result['obs_city']) && $result['obs_city'] != 0) {
  $cityquery = mysqli_query($db,"SELECT city_name FROM obs_cities WHERE city_id='".$result['obs_city']."' LIMIT 1");
  $cityresult = mysqli_fetch_array($cityquery);
  $street_name .= ', '. $cityresult['city_name'];
}
elseif (!empty($result['obs_cityname'])) {
  $street_name .= ', '. $result['obs_cityname'];
}


if (!isset($categorie_string)) {
    jsonError($error_prefix, "unknown categorie_id: ' . $categorie_id", "UNKNOWCATEGORIE", 500);
}

$time = $result['obs_time'];
$approved = $result['obs_approved'];
if ($secretid == $result['obs_secretid'] OR getrole($key, $acls) == "admin" OR getrole($key, $acls) == "moderator") {
  $AdminOrAuthor = True;
}
else {
  $AdminOrAuthor = False;
}

# Check closest issues
$query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat, obs_coordinates_lon, obs_time, obs_token FROM obs_list ORDER BY obs_time DESC");
$additionalmarkers = '';
$color_recent = 'db0000';
$color_month = 'db7800';
$color_old = 'a8a8a8';
$count = 0;
while ($result_issues_coordinates = mysqli_fetch_array($query_issues_coordinates)) {
  if (distance($coordinates_lat, $coordinates_lon, $result_issues_coordinates['obs_coordinates_lat'], $result_issues_coordinates['obs_coordinates_lon'], 'm') < 200 && $result_issues_coordinates['obs_token'] != $token) {
    $osb_time = $result_issues_coordinates['obs_time'];
    if (time() - $osb_time < 3600 * 24 * 30) {
      $color = $color_recent;
    } elseif (time() - $osb_time < 3600 * 24 * 30 * 6) {
      $color = $color_month;
    } else {
      $color = $color_old;
    }

    $additionalmarkers .= $result_issues_coordinates['obs_coordinates_lat'] . ',' . $result_issues_coordinates['obs_coordinates_lon'] . '|via-md-' . $color . '||';

    # mapquestapi limits requests size to 10,240 bytes
    # This limit seems to be reached above ~209 markers.
    # That's why we set a limit and select only 180 last markers.
    if ($count > 180) {
      break;
    } else {
      $count++;
    }
  }
}

## Zoomed map
$size_zoom = '390,390';
$zoom_zoom = 17;
$url_zoom = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $config['MAPQUEST_API'] . '&center=' . $coordinates_lat . ',' . $coordinates_lon . '&size=' . $size_zoom . '&zoom=' . $zoom_zoom . '&locations=' . $additionalmarkers . $coordinates_lat . ',' . $coordinates_lon . '|marker-ff0000&type=hyb';
$map_download_path_zoom = './maps/' . $token . '_zoom.jpg';

if (!file_exists($map_download_path_zoom)) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url_zoom);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // catch output (do NOT print!)
  $content_zoom = curl_exec($ch);

  $http_error_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

  # Check the request went ok and Content-Type is a JPEG image
  if ($http_error_code != 200 || $content_type != 'image/jpeg') {
    // Use default place holder picture instead of crashing
    $map_download_path_zoom = 'panel_components/map_error.jpeg';
    error_log('Unexpected HTTP result HTTP_CODE = ' .$http_error_code . ' - Content-Type = ' .$content_type);
  } else {
    file_put_contents($map_download_path_zoom, $content_zoom);
  }

  curl_close($ch);
}
 
##################################### COMPOSING IMAGE #########################"
## Init other images components :
$photo = imagecreatefromjpeg('./images/' . $token . '.jpg'); // issue photo
$photo_w = imagesx($photo);
$photo_h = imagesy($photo);
$photo_ratio = $photo_w / $photo_h;

# Portrait format
if ($photo_ratio < 1) {
  $backgroung_image = "panel_components/portrait/background.jpg";
  $content_image = "panel_components/portrait/content.png";
  $logo_image = "panel_components/portrait/logo.png";
  $resolved_image = 'panel_components/portrait/resolved.png';

  # Load background image
  $image = imagecreatefromjpeg($backgroung_image);
  $background_w = imagesx($image);
  $background_h = imagesy($image);

  $photo_max_w = 470;
  $photo_max_h = 800;

  $photo_new_size_h = $photo_max_h;
  $photo_new_size_w = $photo_new_size_h * $photo_ratio ;

  while ($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
    $photo_new_size_w--;
    $photo_new_size_h = $photo_new_size_w / $photo_ratio;
  }

  $photo_position_x = 380;
  $photo_min_y = $background_h  - (103 + $photo_new_size_h);
  $map_x = -10;
  $map_y = 543;
  $comment_y_frombottom = 50;
  $comment_span_x = 0;
  $categorie_y = 240;
  $address_y = 330;
  $date_y = 515;
  $copyright_x= 2;
  
  $resolved_x = 520;
  $resolved_y = -15;
  $resolved_w = 300;
  $resolved_h = 150;

}
# Landscape format
else {
  $backgroung_image = "panel_components/landscape/background.jpg";
  $content_image = "panel_components/landscape/content.png";
  $logo_image = "panel_components/landscape/logo.png";
  $resolved_image = 'panel_components/landscape/resolved.png';

  # Load background image
  $image = imagecreatefromjpeg($backgroung_image);
  $background_w = imagesx($image);
  $background_h = imagesy($image);

  $photo_max_w = 900;
  $photo_max_h = 600;

  $photo_new_size_h = $photo_max_h;
  $photo_new_size_w = $photo_new_size_h * $photo_ratio ;
  while ($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
    $photo_new_size_h--;
    $photo_new_size_w = $photo_new_size_h * $photo_ratio;
  }

  $photo_position_x = $background_w-$photo_new_size_w;
  $photo_min_y = 103;

  $map_x = -5;
  $map_y = 438;
  $comment_y_frombottom = 40;
  $comment_span_x = 423;
  $categorie_y = 250;
  $address_y = 330;
  $date_y = 415;
  $copyright_x= 280;

  $resolved_x = 960;
  $resolved_y = -15;
  $resolved_w = 300;
  $resolved_h = 150;


}

## INIT IMAGE ##
$fontfile = './panel_components/texgyreheros-regular.otf';
$fontcolor = imagecolorallocate($image, 54, 66, 86);
$fontcolorgrey = imagecolorallocate($image, 219, 219,219);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
$green = imagecolorallocate($image,35, 122, 68);

## ADD PHOTO
## Photo
# $photo_position_x = $background_w-$photo_new_size_w;
$photo_position_y = $photo_min_y;

# Image is pixelated until approved by a moderator
if ($approved != 1 and !$AdminOrAuthor and $resize_width > 300) {

  $tmpImage = ImageCreateTrueColor($photo_max_w, $photo_max_h);
  $pixelated = ImageCreateTrueColor($photo_w, $photo_h);

  # First resize the picture - $PERCENT_PIXELATED ratio will increase/decrease data loss
  imagecopyresized($tmpImage, $photo, 0, 0, 0, 0, round($photo_max_w / $PERCENT_PIXELATED), round($photo_max_h / $PERCENT_PIXELATED), $photo_w, $photo_h);
  imagecopyresized($pixelated, $tmpImage, 0, 0, 0, 0, $photo_w, $photo_h, round($photo_max_w / $PERCENT_PIXELATED), round($photo_max_h / $PERCENT_PIXELATED));

  # Then apply pixelating + gaussian filters
  imagefilter($pixelated, IMG_FILTER_PIXELATE, 5);

  # Gaussian blur reduces pixel effect on small images
  imagefilter($pixelated, IMG_FILTER_GAUSSIAN_BLUR);

  $photo = $pixelated;
}

imagecopyresized($image, $photo, $photo_position_x, $photo_position_y, 0, 0, $photo_new_size_w, $photo_new_size_h, $photo_w, $photo_h);

## ADD MAP ##
$map_zoom = imagecreatefromjpeg($map_download_path_zoom);
$mask = imagecreatetruecolor(360,360);
$transparent = imagecolorallocate($mask, 255, 0, 0);
$alpha = imagecolorallocate($mask, 0, 0, 0);

$map_zoom_circle = imagecreatetruecolor(360, 360);
imagecopymerge($map_zoom_circle,$map_zoom,0,0,0,0,imagesx($map_zoom),imagesy($map_zoom),100);

imagealphablending($map_zoom_circle, true);

imagecolortransparent($mask,$transparent);

imagefilledellipse($mask, 360/2, 360/2, 360, 360, $transparent);
imagecopymerge($map_zoom_circle, $mask, 0, 0, 0, 0, 360, 360, 100);
imagecolortransparent($map_zoom_circle,$alpha);
imagefill($map_zoom_circle, 0, 0, $alpha);

imagecopymerge($image, $map_zoom_circle, $map_x, $map_y, 0, 0, 360, 360, 100);

## ADD MAP COPYRIGHTS ##
imagettftext($image, 7, 0, $copyright_x, $background_h-13, $white, $fontfile, "©2019 MAPQUEST ©OPENSTREETMAP ©MAPBOX");

## ADD CONTENT BLOCK ##
$content_block = imagecreatefrompng($content_image);
imagecopy($image, $content_block, 0, 0, 0, 0, imagesx($content_block), imagesy($content_block));

## ADD LOGO ##
$logo = imagecreatefrompng($logo_image); // logo #JeSuisUnDesDeux
imagecopy($image, $logo,0,0,0,0,imagesx($logo),imagesy($logo));

## ADD COMMENT
$comment_color = imagecolorallocate($image, 255,219,80);
$comment_font_file = './panel_components/texgyreheros-italic.otf';
$comment_font_size = 25;

if (!empty($comment)) {
  $comment = '"'.trim($comment) . '"';
  $comment_box = imagettfbbox($comment_font_size, 0, $comment_font_file, $comment);
  $comment_x = (($background_w - $comment_span_x) - ($comment_box[2] - $comment_box[0])) / 2;
  imagettftext($image,$comment_font_size ,0,$comment_span_x + $comment_x,$background_h-$comment_y_frombottom,$comment_color,$comment_font_file,$comment);
}

## ADD TOKEN
$token_font_size = 25;
$token_font_file = './panel_components/texgyreheros-regular.otf';
imagettftext($image,$token_font_size,0,200,160,$black,$token_font_file,$token);

## ADD CATEGORIE
$categorie_font_size = 26;
$categorie_max_char_per_line = 18;
$categorie_font_file = './panel_components/texgyreheros-bold.otf';

$categorie_string_formatted = wordwrap($categorie_string, $categorie_max_char_per_line, "===");
$categories_nblines = substr_count($categorie_string_formatted,"===");

if($categories_nblines > 1) {
  $categorie_max_char_per_line = 25;
  $categorie_string_formatted = wordwrap($categorie_string, $categorie_max_char_per_line, "===");
}

$categories_lines = explode('===',$categorie_string_formatted);
foreach($categories_lines as $categories_line) {
  imagettftext($image,$categorie_font_size,0,29,$categorie_y,$black,$categorie_font_file,$categories_line);
  $categorie_y += 35;
}

## ADD ADDRESS
$address_font_size = 18;
$address_max_char_per_line = 25;
$address_font_file = './panel_components/texgyreheros-bold.otf';
$street_name = wordwrap($street_name,$address_max_char_per_line,"===");

$address_lines = explode('===',$street_name);

foreach($address_lines as $address_line) {
  imagettftext($image,$address_font_size,0,29,$address_y,$black,$address_font_file,$address_line);
  $address_y += 28;
}

## ADD DATE
$date = date('d/m/Y H:i', $time);
$date_font_size = 15;
$date_font_file = './panel_components/texgyreheros-regular.otf';
imagettftext($image,$date_font_size,0,29,$date_y,$black,$date_font_file,$date);

## ADD RESOLVED
if($statusobs == 1) {
  $resolved = imagecreatefrompng($resolved_image);
  imagecopyresized($image, $resolved, $resolved_x, $resolved_y, 0, 0, $resolved_w, $resolved_h,imagesx($resolved), imagesy($resolved));
}

# Generate full size image
if ($AdminOrAuthor && $resize_width == $MAX_IMG_SIZE) {
  imagejpeg($image);
}
else if ($resize_width == $MAX_IMG_SIZE) {
  # Use user original image
  imagejpeg($image, $img_filename);
  imagejpeg($image);
} else {
  # Resize image
  $panel_ratio = $background_w / $background_h;
  $resize_height  = $resize_width / $panel_ratio;
  $imageresized = imagecreatetruecolor($resize_width, $resize_height);

  imagecopyresampled($imageresized, $image, 0, 0, 0, 0, $resize_width, $resize_height, $background_w, $background_h);
  imagejpeg($imageresized, $img_filename);
  imagejpeg($imageresized);
}

