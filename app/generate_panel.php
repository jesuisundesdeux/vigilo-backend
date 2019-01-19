<?php
header ("Content-type: image/png");
require_once('./common.php');
date_default_timezone_set('Europe/Paris');


# Get issue information
$token = mysqli_real_escape_string($db,$_GET['token']);
$query = mysqli_query($db,"SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1") or die(mysqli_error());

if(mysqli_num_rows($query) == 1) {

  $result = mysqli_fetch_array($query);
  $coordinates_lat=$result['obs_coordinates_lat'];
  $coordinates_lon=$result['obs_coordinates_lon'];
  $comment=$result['obs_comment'];
  $time=$result['obs_time'];

  $qcount = mysqli_query($db,"SELECT count(*) as nbsignalement FROM obs_list") or die(mysqli_error());
  $nbsignalement=0;
  if(mysqli_num_rows($qcount) == 1) {
    $rcount = mysqli_fetch_array($qcount);
    $nbsignalement=$rcount['nbsignalement'];
  }
  
  # Street information created by create_issue
  $street_download_path = './places/'.$token.'.json';
  $json_content = file_get_contents($street_download_path);
  $json_street = json_decode($json_content, true); 
  $street_name = $json_street['results'][0]['locations'][0]['street'];

  ## Wide map
  $size='390,350';
  $zoom=14;
  $url='https://www.mapquestapi.com/staticmap/v5/map?key='.$mapquestapi_key.'&center='.$coordinates_lat.','.$coordinates_lon.'&size='.$size.'&zoom='.$zoom.'&locations='.$coordinates_lat.','.$coordinates_lon;
  $map_download_path = './maps/'.$token.'.jpg';
  
  if(!file_exists($map_download_path)) {
  	$content = file_get_contents($url);
  	file_put_contents($map_download_path, $content);
  }
  
  $map = imagecreatefromjpeg($map_download_path);
  
  ## Zoomed map
  $size_zoom='390,390';
  $zoom_zoom=19;
  $url_zoom='https://www.mapquestapi.com/staticmap/v5/map?key='.$mapquestapi_key.'&center='.$coordinates_lat.','.$coordinates_lon.'&size='.$size_zoom.'&zoom='.$zoom_zoom.'&locations='.$coordinates_lat.','.$coordinates_lon.'&type=hyb';
  $map_download_path_zoom = './maps/'.$token.'_zoom.jpg';
    
  if(!file_exists($map_download_path_zoom)) {
  	$content_zoom = file_get_contents($url_zoom);
  	file_put_contents($map_download_path_zoom, $content_zoom);
  }
  $map_zoom = imagecreatefromjpeg($map_download_path_zoom);
  
  ## Init other images components :
  $image = imagecreatefrompng('./fondjsuisundesdeux.png'); // background
  $photo = imagecreatefromjpeg('./images/'.$token.'.jpg'); // issue photo
  $logo = imagecreatefrompng('./jssud.png'); // logo #JeSuisUnDesDeux
  
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
  	$boxtxt = imagettfbbox($fontsize,0,$fontfile,$comment);
  
  } while(($boxtxt[2]-$boxtxt[0]) > 800 && $fontsize > 20);
  
  $boxtxt = imagettfbbox($fontsize,0,$fontfile,$comment);
  $comment_x=130+(800-($boxtxt[2]-$boxtxt[0])) / 2;
  
  imagettftext($image,$fontsize,0,10+$comment_x,60,$white,$fontfile,$comment);
  
  # draw Street
  imagettftext($image,20,0,120,120,$white,$fontfile,$street_name);
  
  ### Date
  $date = date('d/m/Y H:i',$time);
  $boxdate = imagettfbbox(16,0,'./DejaVuSans.ttf',$date);
  $date_size = $boxdate[2]-$boxdate[0]; 
  $date_x=(1024-$date_size) / 2;
  $date_x=1024-$date_size-20;
  
  imagettftext($image,16,0,$date_x,120,$white,$fontfile,$date);
  
  ## Wide Map
  imagecopymerge ( $image, $map, 5, 135, 0,0 , 390,350, 60 );
  
  ## Photo
  $photo_size_x = imagesx($photo);
  $photo_size_y = imagesy($photo);
  
  $ratio = $photo_size_x / $photo_size_y;
  $photo_canvas_w = 1024-380;
  $photo_canvas_h = 768-135;
  
  if($photo_size_x > $photo_size_y) {
  	$photo_new_size_x = $photo_canvas_w;
  	$photo_new_size_y = $photo_size_y;
  	while($photo_new_size_y > $photo_canvas_h or $photo_new_size_x > $photo_canvas_w) {
  	    $photo_new_size_y--;
  	    $photo_new_size_x = $photo_new_size_y*$ratio;
  	}
  } elseif($photo_size_x < $photo_size_y) {
  	$photo_new_size_y = $photo_canvas_h;
  	$photo_new_size_x = $photo_size_x;
  	while($photo_new_size_y > $photo_canvas_h or $photo_new_size_x > $photo_canvas_w) {
  	    $photo_new_size_x--;
  	    $photo_new_size_y = $photo_new_size_x/$ratio;
  	}
  }
  else {
  	$photo_new_size_x = $photo_canvas_w;
  	$photo_new_size_y = $photo_canvas_h;
  
  }
  $photo_x = 375+(($photo_canvas_w-$photo_new_size_x)/2);
  $photo_y = 130+(($photo_canvas_h-$photo_new_size_y)/2);
  
  imagecopyresized ( $image, $photo, $photo_x, $photo_y, 0, 0, $photo_new_size_x, $photo_new_size_y, $photo_size_x, $photo_size_y );
  
  # Logo
  imagecopy ( $image, $logo,2,6,0,0,125,125);
  
  ## Zoomed map
  imagecopymerge ( $image, $map_zoom,5,400,0,0,390,360,100);
  
  # Nb Signalements
  $tsignalement=$nbsignalement . " signalements";
  imagefilledrectangle ($image, 0,730,396,760,$black);
  imagettftext($image,14,0,5,754,$white,$fontfile,$tsignalement);
  
  ## Generate image
  imagepng($image);

} 
else {
  error_log('GENERATE_IMAGE : Token '.$token.' not found');
  http_response_code(500);
}
