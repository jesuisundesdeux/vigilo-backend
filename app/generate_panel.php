<?php
header("Content-type: image/png");
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

require_once('./functions.php');

$MAX_IMG_SIZE = 1024; // For limit attack
$resize_width = $MAX_IMG_SIZE; // default width
$PERCENT_PIXELATED=5;

if(isset($_GET['key'])) {
  $key = $_GET['key'];
}
else {
  $key = Null;
}


$token = mysqli_real_escape_string($db, $_GET['token']);

if(isset($_GET['secretid'])) {
  $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
}
else {
  $secretid = Null;
}

if (isset($_GET["s"]) and is_numeric($_GET["s"]) and intval($_GET["s"]) <= $MAX_IMG_SIZE) {
  $resize_width = intval($_GET["s"]);
  $img_filename = './caches/' . $token . '_w' . $resize_width . '.png';
} else {
  $img_filename = './caches/' . $token . '_full.png';
}

## Use caches if available
if (file_exists($img_filename) AND !getrole($key, $acls) == "admin") {
  $image = imagecreatefrompng($img_filename);
  imagepng($image);
  return;
}

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");

if (mysqli_num_rows($query) != 1) {
  error_log('GENERATE_IMAGE : Token ' . $token . ' not found');
  http_response_code(500);
  return;
}

$result = mysqli_fetch_array($query);
$coordinates_lat = $result['obs_coordinates_lat'];
$coordinates_lon = $result['obs_coordinates_lon'];
$street_name = $result['obs_address_string'];
$comment = $result['obs_comment'];
$categorie_id = $result['obs_categorie'];
foreach($categorie_lst as $value) {
  if($value['catid'] == $categorie_id) { 
    $categorie_string = $value['catname'];
  }
}
$time = $result['obs_time'];
$approved = $result['obs_approved'];
if($secretid == $result['obs_secretid'] OR getrole($key, $acls) == "admin") {
  $approved = 1;
}

# Check closest issues
$query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat,obs_coordinates_lon,obs_time,obs_token FROM obs_list");
$additionalmarkers = '';
$color_recent = 'db0000';
$color_month = 'db7800';
$color_old = 'a8a8a8';
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
  }
}

## Wide map
/*$size = '390,350';
$zoom = 14;
$url = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquestapi_key . '&center=' . $coordinates_lat . ',' . $coordinates_lon . '&size=' . $size . '&zoom=' . $zoom . '&locations=' . $coordinates_lat . ',' . $coordinates_lon;
$map_download_path = './maps/' . $token . '.jpg';

if (!file_exists($map_download_path)) {
  $content = file_get_contents($url);
  file_put_contents($map_download_path, $content);
}*/

//$map = imagecreatefromjpeg($map_download_path);

## Zoomed map
$size_zoom = '390,390';
$zoom_zoom = 17;
$url_zoom = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquestapi_key . '&center=' . $coordinates_lat . ',' . $coordinates_lon . '&size=' . $size_zoom . '&zoom=' . $zoom_zoom . '&locations=' . $additionalmarkers . $coordinates_lat . ',' . $coordinates_lon . '|marker-ff0000&type=hyb';
$map_download_path_zoom = './maps/' . $token . '_zoom.jpg';

if (!file_exists($map_download_path_zoom)) {
  $content_zoom = file_get_contents($url_zoom);
  file_put_contents($map_download_path_zoom, $content_zoom);
}

 
##################################### COMPOSING IMAGE #########################"
## Init other images components :
$photo = imagecreatefromjpeg('./images/' . $token . '.jpg'); // issue photo
$photo_w = imagesx($photo);
$photo_h = imagesy($photo);
$photo_ratio = $photo_w / $photo_h;

if($photo_ratio < 1) {
  $backgroung_image = "panel_components/portrait/background.jpg";
  $content_image = "panel_components/portrait/content.png";
  $logo_image = "panel_components/portrait/logo.png";

  $image = imagecreatefromjpeg($backgroung_image); // background
  $background_w = imagesx($image);
  $background_h = imagesy($image);

  $photo_max_w = 470;
  $photo_max_h = 800;

  $photo_new_size_h = $photo_max_h;
  $photo_new_size_w = $photo_new_size_h * $photo_ratio ;

  while($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
    $photo_new_size_w--;
    $photo_new_size_h=$photo_new_size_w / $photo_ratio;
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

}
else {
  $backgroung_image = "panel_components/landscape/background.jpg";
  $content_image = "panel_components/landscape/content.png";
  $logo_image = "panel_components/landscape/logo.png";

  $image = imagecreatefromjpeg($backgroung_image); // background
  $background_w = imagesx($image);
  $background_h = imagesy($image);

  $photo_max_w = 900;
  $photo_max_h = 600;

  $photo_new_size_h = $photo_max_h;
  $photo_new_size_w = $photo_new_size_h * $photo_ratio ;
  while($photo_new_size_w > $photo_max_w or $photo_new_size_h > $photo_max_h) {
    $photo_new_size_h--;
    $photo_new_size_w=$photo_new_size_h * $photo_ratio;
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
}

## INIT IMAGE ##
$fontfile = './panel_components/texgyreheros-regular.otf';
$fontcolor = imagecolorallocate($image, 54, 66, 86);
$fontcolorgrey = imagecolorallocate($image, 219, 219,219);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);

## ADD PHOTO
## Photo
# $photo_position_x = $background_w-$photo_new_size_w;
$photo_position_y = $photo_min_y;

if ( ! $approved and $resize_width>300) {
  # Pixelate user image 
  $tmpImage = ImageCreateTrueColor($photo_w, $photo_h);
  imagecopyresized($tmpImage, $photo, 0, 0, 0, 0, round($photo_w / $PERCENT_PIXELATED), round($photo_h / $PERCENT_PIXELATED), $photo_w, $photo_h);

  $pixelated = ImageCreateTrueColor($photo_w, $photo_h);
  imagecopyresized($pixelated, $tmpImage, 0, 0, 0, 0, $photo_w, $photo_h, round($photo_w / $PERCENT_PIXELATED), round($photo_h / $PERCENT_PIXELATED));

  $photo = $pixelated ;
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

if(!empty($comment)) {
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
 
$categorie_string = wordwrap($categorie_string, $categorie_max_char_per_line, "\n"); 

/*  do {
  $categorie_max_char_per_line--;
  $categorie_string = str_replace('\n','',$categorie_string);
  $categorie_string = wordwrap($categorie_string, $categorie_max_char_per_line, "\n");
  $categorie_box = imagettfbbox($categorie_font_size,0,$categorie_font_file,$categorie_string);
} while(($categorie_box[2] - $categorie_box[0]) > 380);*/
imagettftext($image,$categorie_font_size,0,29,$categorie_y,$black,$categorie_font_file,$categorie_string);

## ADD ADDRESS
$address_font_size = 18;
$address_max_char_per_line = 25;
$address_font_file = './panel_components/texgyreheros-bold.otf';
$street_name = wordwrap($street_name,$address_max_char_per_line,"\n");

imagettftext($image,$address_font_size,0,29,$address_y,$black,$address_font_file,$street_name);

## ADD DATE 
$date = date('d/m/Y H:i', $time);
$date_font_size = 15;
$date_font_file = './panel_components/texgyreheros-regular.otf';
imagettftext($image,$date_font_size,0,29,$date_y,$black,$date_font_file,$date);

# Generate full size image
if($secretid == $result['obs_secretid'] && $resize_width == $MAX_IMG_SIZE) {
  imagepng($image);
}
else if ($resize_width == $MAX_IMG_SIZE) {
  # Use user original image
  imagepng($image, $img_filename);
  imagepng($image);
} else {
  # Resize image
  $panel_ratio = $background_w / $background_h;
  $resize_height  = $resize_width / $panel_ratio;
  $imageresized = imagecreatetruecolor($resize_width, $resize_height);
 
  imagecopyresampled($imageresized, $image, 0, 0, 0, 0, $resize_width, $resize_height, $background_w, $background_h);
  imagepng($imageresized, $img_filename);
  imagepng($imageresized);
}
