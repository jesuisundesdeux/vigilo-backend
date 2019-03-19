<?php
require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

require_once('./functions.php');
$BEFORE_TIME=time() - (2*24 * 60 * 60);

$scategorie=-1;
if (isset($_GET["c"]) and is_numeric($_GET["c"])) {
  $scategorie=intval($_GET["c"]);
}

# filter observations last 24h
$stoday=isset($_GET["t"]);

# Filter observations by categories
$where="";
if ($stoday or $scategorie > -1) {
  $where=" Where";
}

if ($scategorie > -1) {
  $where .= " AND obs_categorie=".$scategorie;
}

if ($stoday) {
  $where .= " AND obs_time>".$BEFORE_TIME;
}

$query = mysqli_query($db, "SELECT obs_token,obs_coordinates_lat,obs_coordinates_lon,obs_address_string,obs_comment,obs_time,obs_categorie,obs_approved FROM obs_list WHERE obs_complete=1 ".$where." ORDER BY obs_time DESC");
# Export categories
$json = array();

if (mysqli_num_rows($query) > 0) {
  while ($result = mysqli_fetch_array($query)) {
    $token = $result['obs_token'];
    $issue = array("token" => $result['obs_token'],
                   "coordinates_lat" => $result['obs_coordinates_lat'],
                   "coordinates_lon" => $result['obs_coordinates_lon'],
                   "address"=>$result['obs_address_string'],
                   "comment"=> $result['obs_comment'],
                   "time"=>$result['obs_time'],
                   "group"=>0,
                   "categorie"=>$result['obs_categorie'],
                   "approved"=>$result['obs_approved']);
		if(isset($_GET['lat']) && isset($_GET['lon']) && is_numeric($_GET['radius'])) {
      $lat = mysqli_real_escape_string($db,$_GET['lat']);
      $lon = mysqli_real_escape_string($db,$_GET['lon']);
      $radius = intval($_GET['radius']);
      if(distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm') <= $radius) {
        $issue['distance'] = distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm');
        $json[] = $issue;
      }
    }
    else {
      $json[] = $issue;
    }
  }
}

echo json_encode($json,JSON_PRETTY_PRINT);
