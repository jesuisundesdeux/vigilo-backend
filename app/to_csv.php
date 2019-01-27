<?php
require_once('./common.php');
require_once('./functions.php');
$BEFORE_TIME=time() - (2*24 * 60 * 60);

date_default_timezone_set('Europe/Paris');
$separator = ',';

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


$query = mysqli_query($db, "SELECT * FROM obs_list".$where);

# Export categories
if (mysqli_num_rows($query) > 0) {
  echo 'lat' . $separator . 'long' . $separator . 'rue' . $separator . 'comment' . $separator . 'categorie' . $separator . 'token' . $separator . "time\n";
  while ($result = mysqli_fetch_array($query)) {
    $coordinates_lat = $result['obs_coordinates_lat'];
    $coordinates_lon = $result['obs_coordinates_lon'];
    $street_name = $result['obs_address_string'];
    $comment = $result['obs_comment'];
    $categorie = $result['obs_categorie'];
    $token = $result['obs_token'];
    $time = $result['obs_time'];
    $status = $result['obs_status'];
    $version = $result['obs_app_version'];

    $line = $coordinates_lat . '~' . $coordinates_lon . '~' . $street_name . '~' . $comment . '~' . $categorie . '~' . $token . '~' . $time . "\n";
    $line = str_replace(',', '_', $line);
    $line = str_replace('~', ',', $line);
    echo $line;
  }
}