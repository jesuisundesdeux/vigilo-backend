<?php
require_once('./common.php');
require_once('./functions.php');
$BEFORE_TIME=time() - (2*24 * 60 * 60);

date_default_timezone_set('Europe/Paris');

$scategorie=-1;
if (isset($_GET["c"]) and is_numeric($_GET["c"])) {
  $scategorie=intval($_GET["c"]);
}

# filter observations last 24h
$stoday=isset($_GET["t"]);

# Filter observations by categories
$filtered=false;
$where="";
if ($stoday or $scategorie > -1) {
  $where=" Where";
}

if ($scategorie > -1) {
  if ($filtered) {
    $where .= " And";
  }
  $where .= " obs_categorie=".$scategorie;
  $filtered=true;
}

if ($stoday) {
  if ($filtered) {
    $where .= " And";
  }
  $where .= " obs_time>".$BEFORE_TIME;
  $filtered=true;
}

mysqli_set_charset( $db, 'utf8');

$query = mysqli_query($db, "SELECT obs_token,obs_coordinates_lat,obs_coordinates_lon,obs_address_string,obs_comment,obs_time FROM obs_list".$where);
# Export categories
$json = array();

if (mysqli_num_rows($query) > 0) {
  while ($result = mysqli_fetch_array($query)) {
    $token = $result['obs_token'];
#    $json[$token] = json_encode($result);
     $json[] = array("token" => $result['obs_token'],"LAT" => $result['obs_coordinates_lat'],"LON" => $result['obs_coordinates_lon'],"address"=>$result['obs_address_string'],"comment"=> $result['obs_comment'],"time"=>$result['obs_time']);
  }
}
echo json_encode($json,JSON_PRETTY_PRINT);
