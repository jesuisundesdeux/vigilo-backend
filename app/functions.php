<?php
require_once('lib/codebird-php/codebird.php');
// https://numa-bord.com/miniblog/php-calcul-de-distance-entre-2-coordonnees-gps-latitude-longitude/
function distance($lat1, $lng1, $lat2, $lng2, $unit = 'k') {
        $earth_radius = 6378137;   // Terre = sphère de 6378km de rayon
        $rlo1 = deg2rad($lng1);
        $rla1 = deg2rad($lat1);
        $rlo2 = deg2rad($lng2);
        $rla2 = deg2rad($lat2);
        $dlo = ($rlo2 - $rlo1) / 2;
        $dla = ($rla2 - $rla1) / 2;
        $a = (sin($dla) * sin($dla)) + cos($rla1) * cos($rla2) * (sin($dlo) * sin($dlo));
        $d = 2 * atan2(sqrt($a), sqrt(1 - $a));
        //
        $meter = ($earth_radius * $d);
        if ($unit == 'k') {
            return $meter / 1000;
        }
        return $meter;
}

function delete_token_cache($token) {
    foreach(glob(__DIR__."/caches/".$token."*") as $file) {
        unlink($file);
    }
}
function tweet($text,$image) {
   \Codebird\Codebird::setConsumerKey(getenv("TWITTER_CONSUMER"), getenv("TWITTER_CONSUMERSECRET"));
  $cb = \Codebird\Codebird::getInstance();
  $cb->setToken(getenv("TWITTER_ACCESSTOKEN"), getenv("TWITTER_ACCESSTOKENSECRET"));
$reply = $cb->media_upload(array(
    'media' => $image
));
$mediaID = $reply->media_id_string;


  $params = array(
    'status' => $text,
    'media_ids' => $mediaID
  );
$reply = $cb->statuses_update($params);

}

function getrole($privatekey, $acls) {
  foreach($acls as $key => $value) {
    if(in_array($privatekey,$value)) {
      return $key;
    }
  }
  return False; 
}

function generategroups($db,$filter = array('distance' => 500,'fdistance' => 0, 'fcategorie' => 0,'faddress' => 0)) {
  $query = mysqli_query($db,"SELECT * FROM obs_list");
  $groups = array();
  while($result = mysqli_fetch_array($query)) {
    $in_group = 0;
    $token = $result['obs_token'];
    $categorie = $result['obs_categorie'];
    $coordinates_lat = $result['obs_coordinates_lat'];
    $coordinates_lon = $result['obs_coordinates_lon'];
    $address = $result['obs_address_string'];
    foreach($groups as $key => $value) {
      if(($value['categorie'] == $categorie OR $filter['fcategorie'] == 0) AND 
        (str_replace(' ','',$value['address_string']) == str_replace(' ','',$address) OR $filter['faddress'] == 0) AND
        (distance($value['coordinates_lat'], $value['coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < $filter['distance'] OR $filter['fdistance'] == 0)) {
  	      $groups[$key]['tokens'][] = $token;
  	      $groups[$key]['count']++;
  	      $in_group = 1;
  	      break;
      }
   }
   if($in_group == 0) {
     $groups[] = array("categorie" => $categorie, 'address_string' => $address, 'coordinates_lat' => $coordinates_lat, 'coordinates_lon' => $coordinates_lon, 'tokens' => array($token), 'count' => 1);
   }
 }
 return $groups;
}

function sameas($db,$token,$filter=array()) {
  $groups = generategroups($db,$filter);
  foreach($groups as $value) {
    if(in_array($token,$value['tokens'])) {
      return $value['tokens'];
    }
  }
}

