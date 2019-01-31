<?php
header("Content-type: image/png");
require_once('./common.php');
require_once('./functions.php');
date_default_timezone_set('Europe/Paris');
$MAX_IMG_SIZE = 1024; // For limit attack
$PERCENT_PIXELATED=5;

$token = mysqli_real_escape_string($db, $_GET['token']);
$secretid = mysqli_real_escape_string($db, $_GET['secretid']);

if (isset($_GET["s"]) and is_numeric($_GET["s"]) and intval($_GET["s"]) < $MAX_IMG_SIZE) {
  $resize_with = intval($_GET["s"]);
  $img_filename = './caches/' . $token . '_w' . $resize_with . '.png';
} else {
  $resize_with = -1;
  $img_filename = './caches/' . $token . '_full.png';
}

## Use caches if available
if (file_exists($img_filename)) {
  $image = imagecreatefrompng($img_filename);
  imagepng($image);
  return;
}

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");
#$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' AND obs_secretid='".$secretid."' LIMIT 1");

if (mysqli_num_rows($query) == 1) {
  $result = mysqli_fetch_array($query);
  $coordinates_lat = $result['obs_coordinates_lat'];
  $coordinates_lon = $result['obs_coordinates_lon'];
  $street_name = $result['obs_address_string'];
  $comment = $result['obs_comment'];
  $time = $result['obs_time'];
  $approved = $result['obs_approved'];
  $nbsignalement = 1;
  
  # Check closest issues
  $query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat,obs_coordinates_lon,obs_time,obs_token FROM obs_list");
  $additionalmarkers = '';
  $color_recent = 'db0000';
  $color_month = 'db7800';
  $color_old = 'a8a8a8';
  while ($result_issues_coordinates = mysqli_fetch_array($query_issues_coordinates)) {
    if (distance($coordinates_lat, $coordinates_lon, $result_issues_coordinates['obs_coordinates_lat'], $result_issues_coordinates['obs_coordinates_lon'], 'm') < 30 && $result_issues_coordinates['obs_token'] != $token) {
      $osb_time = $result_issues_coordinates['obs_time'];
      if (time() - $osb_time < 3600 * 24 * 30) {
        $color = $color_recent;
      } elseif (time() - $osb_time < 3600 * 24 * 30 * 6) {
        $color = $color_month;
      } else {
        $color = $color_old;
      }

      $nbsignalement++;

      $additionalmarkers .= $result_issues_coordinates['obs_coordinates_lat'] . ',' . $result_issues_coordinates['obs_coordinates_lon'] . '|via-md-' . $color . '||';
    }
  }
  # Street information created by create_issue
  #$street_download_path = './places/'.$token.'.json';
  #$json_content = file_get_contents($street_download_path);
  #$json_street = json_decode($json_content, true); 
  #$street_name = $json_street['results'][0]['locations'][0]['street'];

  ## Wide map
  $size = '390,350';
  $zoom = 14;
  $url = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquestapi_key . '&center=' . $coordinates_lat . ',' . $coordinates_lon . '&size=' . $size . '&zoom=' . $zoom . '&locations=' . $coordinates_lat . ',' . $coordinates_lon;
  #https://www.mapquestapi.com/staticmap/v5/map?key=gEiOG0t0mVAO4fW6EliL2X7sJ9VTLdyN&center=43.59892875839891,3.90309290376607&size=390,390&locations=43.59892875839891,3.90309290376607&type=hyb&zoom=19&shape=radius:0.03km|border:000000|fill:d6d4d490|weight:0|43.59892875839891,3.90309290376607
  $map_download_path = './maps/' . $token . '.jpg';

  if (!file_exists($map_download_path)) {
    $content = file_get_contents($url);
    file_put_contents($map_download_path, $content);
  }

  $map = imagecreatefromjpeg($map_download_path);
  
  ## Zoomed map
  $size_zoom = '390,390';
  $zoom_zoom = 19;
  $url_zoom = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquestapi_key . '&center=' . $coordinates_lat . ',' . $coordinates_lon . '&size=' . $size_zoom . '&zoom=' . $zoom_zoom . '&locations=' . $additionalmarkers . $coordinates_lat . ',' . $coordinates_lon . '|marker-ff0000&type=hyb';
  $map_download_path_zoom = './maps/' . $token . '_zoom.jpg';

  if (!file_exists($map_download_path_zoom)) {
    $content_zoom = file_get_contents($url_zoom);
    file_put_contents($map_download_path_zoom, $content_zoom);
  }
  $map_zoom = imagecreatefromjpeg($map_download_path_zoom);
  
  ## Init other images components :
  $image = imagecreatefrompng('./fondjsuisundesdeux.png'); // background
  $photo = imagecreatefromjpeg('./images/' . $token . '.jpg'); // issue photo
  $logo = imagecreatefrompng('./jssud.png'); // logo #JeSuisUnDesDeux
  $background_w = imagesx($image);
  $background_h = imagesy($image);
  
  if ( ! $approved and ($resize_with == -1 or $resize_with>600)) {
    # Pixelate user image
    $reduced = imagecreatetruecolor($background_w, $background_h);
    imagecopyresized($reduced, $photo, 0, 0, 0, 0, round($background_w / $PERCENT_PIXELATED), round($background_h / $PERCENT_PIXELATED), $background_w, $background_h);

    $photo = imagecreatetruecolor($background_w, $background_h);
    imagecopyresized($photo, $reduced, 0, 0, 0, 0, $background_w, $background_h, round($background_w / $PERCENT_PIXELATED), round($background_h / $PERCENT_PIXELATED));
}


  # Create image
  
  ## Text
  $fontcolor = imagecolorallocate($image, 54, 66, 86);
  $fontfile = './DejaVuSans.ttf';
  $fontsize = 41;
  $white = imagecolorallocate($image, 255, 255, 255);
  $red = imagecolorallocate($image, 255, 0, 0);
  $black = imagecolorallocate($image, 0, 0, 0);
  
  ### Title / comment
  do {
    $fontsize--;
    $boxtxt = imagettfbbox($fontsize, 0, $fontfile, $comment);

  } while (($boxtxt[2] - $boxtxt[0]) > 800 && $fontsize > 20);

  $boxtxt = imagettfbbox($fontsize, 0, $fontfile, $comment);
  $comment_x = 130 + (800 - ($boxtxt[2] - $boxtxt[0])) / 2;

  imagettftext($image, $fontsize, 0, 10 + $comment_x, 80, $white, $fontfile, $comment);
 

  # draw ID 
  $issue_id_txt_fontsize = 16;
  $issue_id_txt = $token;
  $box_issue_id = imagettfbbox($issue_id_txt_fontsize, 0, $fontfile, $issue_id_txt);
  $issue_id_size = $box_issue_id[2] - $box_issue_id[0];
  $issue_id_txt_x = 1024 - $issue_id_size - 20;
  imagettftext($image, $issue_id_txt_fontsize, 0, $issue_id_txt_x, 30, $white, $fontfile, $issue_id_txt);

  # draw Street
  imagettftext($image, 16, 0, 120, 120, $white, $fontfile, $street_name);
  
  ### Date
  $date = date('d/m/Y H:i', $time);
  $boxdate = imagettfbbox(16, 0, './DejaVuSans.ttf', $date);
  $date_size = $boxdate[2] - $boxdate[0];
  $date_x = 1024 - $date_size - 20;

  imagettftext($image, 16, 0, $date_x, 120, $white, $fontfile, $date);
  
  ## Wide Map
  imagecopymerge($image, $map, 5, 135, 0, 0, 390, 350, 90);
  
  ## Photo
  $photo_size_x = imagesx($photo);
  $photo_size_y = imagesy($photo);

  $ratio = $photo_size_x / $photo_size_y;
  $photo_canvas_w = 1024 - 380;
  $photo_canvas_h = 768 - 135;

  if ($photo_size_x > $photo_size_y) {
    $photo_new_size_x = $photo_canvas_w;
    $photo_new_size_y = $photo_new_size_x / $ratio;
    while ($photo_new_size_y > $photo_canvas_h or $photo_new_size_x > $photo_canvas_w) {
      $photo_new_size_y--;
      $photo_new_size_x = $photo_new_size_y * $ratio;
    }
  } elseif ($photo_size_x < $photo_size_y) {
    $photo_new_size_y = $photo_canvas_h;
    $photo_new_size_x = $photo_new_size_y * $ratio ;
    while($photo_new_size_x > $photo_canvas_w or $photo_new_size_y > $photo_canvas_h) {
      $photo_new_size_x--;
      $photo_new_size_y=$photo_new_size_x / $ratio;
    }
  } else {
    if($photo_canvas_w > $photo_canvas_h) {
      $photo_new_size_x = $photo_new_size_y = $photo_canvas_h;
    }
    else {
      $photo_new_size_x = $photo_new_size_y = $photo_canvas_w;
    }
  }
  $photo_x = 375 + (($photo_canvas_w - $photo_new_size_x) / 2);
  $photo_y = 130 + (($photo_canvas_h - $photo_new_size_y) / 2);

  imagecopyresized($image, $photo, $photo_x, $photo_y, 0, 0, $photo_new_size_x, $photo_new_size_y, $photo_size_x, $photo_size_y);
  
  # Logo
  imagecopy($image, $logo, 2, 6, 0, 0, 125, 125);
  
  ## Zoomed map
  imagecopymerge($image, $map_zoom, 5, 400, 0, 0, 390, 360, 100);
  
  # Nb Signalements
  $tsignalement = $nbsignalement . " signalement(s) dans cette zone";
  imagefilledrectangle($image, 0, 730, 396, 760, $black);
  imagettftext($image, 14, 0, 10, 754, $white, $fontfile, $tsignalement);
  
  
  # Generate full size image
  if ($resize_with == -1) {
      # Use user original image
      imagepng($image, $img_filename);
      imagepng($image);
  } else {
    # Resize image
    $ratio = $background_w / $resize_with;
    $imageresized = imagecreatetruecolor($resize_with, intval($background_h / $ratio));
    imagecopyresampled($imageresized, $image, 0, 0, 0, 0, $resize_with, intval($background_h / $ratio), 1024, 768);
    imagepng($imageresized, $img_filename);
    imagepng($imageresized);
  }
} else {
  error_log('GENERATE_IMAGE : Token ' . $token . ' and/or secretid : ' . $secretid . ' not found');
  http_response_code(500);
}
