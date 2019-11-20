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

require_once(dirname(__FILE__) . '/../lib/codebird-php/codebird.php');

function tokenGenerator($length) {
  $bytes = random_bytes($length);
  return strtoupper(bin2hex($bytes));
}


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

function get_data_from_gps_coordinates($lat, $lon)
{
  $options = array(
    'http'=>array(
      'method'=>"GET",
      'header'=>"User-Agent: Vigilo Backend Version/".BACKEND_VERSION." \r\n"
    )
  );
  // MAX 1 request per second
  // https://operations.osmfoundation.org/policies/nominatim/
  $url='https://nominatim.openstreetmap.org/reverse?format=json&lat='.$lat.'&lon='.$lon;
  $context = stream_context_create($options);
  $resp_json = file_get_contents($url, false, $context);
  $resp = json_decode($resp_json, true);
  return $resp;
}

function delete_token_cache($token) {
    foreach(glob(__DIR__."/../caches/".$token."*") as $file) {
        unlink($file);
    }
}
function delete_map_cache($token) {
    foreach(glob(__DIR__."/../maps/".$token."*") as $file) {
        unlink($file);
    }
}
function tweet($text,$image,$twitter_ids) {
   \Codebird\Codebird::setConsumerKey($twitter_ids['consumer'], $twitter_ids['consumersecret']);
  $cb = \Codebird\Codebird::getInstance();
  $cb->setToken($twitter_ids['accesstoken'], $twitter_ids['accesstokensecret']);
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
    if(!empty($privatekey) && in_array($privatekey,$value)) {
      return $key;
    }
  }
  return False; 
}

function flatstring($string) {
  return str_replace(' ','',str_replace('-','',strtolower(trim($string)))); 
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
    $cityid = $result['obs_city'];
    $citycondition = True;

    foreach($groups as $key => $value) {
      if ($cityid != "0") {
        if($cityid == $value['cityid']) {
          $citycondition = True;
        }
        else {
          $citycondition = False;
        }
      }

      if($value['categorie'] == $categorie OR $filter['fcategorie'] == 0) {
        if(((flatstring($value['address_string']) == flatstring($address) && $citycondition) OR $filter['faddress'] == 0) OR
        (distance($value['coordinates_lat'], $value['coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < $filter['distance'] OR $filter['fdistance'] == 0)) {
          $groups[$key]['tokens'][] = $token;
          $groups[$key]['count']++;
          $in_group = 1;
        break;
        }
      }
   }
   if($in_group == 0) {
     $groups[] = array("cityid" => $cityid, "categorie" => $categorie, 'address_string' => $address, 'coordinates_lat' => $coordinates_lat, 'coordinates_lon' => $coordinates_lon, 'tokens' => array($token), 'count' => 1);
   }
 }
 return $groups;
}

function sameas($db,$token,$filter=array()) {
  $tokenquery = mysqli_query($db,"SELECT obs_categorie,obs_address_string,obs_city,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  $tokenresult = mysqli_fetch_array($tokenquery);

  $similar = array();

  $where = '';
  if($filter['fcategorie'] == 1) {
    $where .= "obs_categorie='".$tokenresult['obs_categorie']."' AND ";
  }
  if($filter['faddress'] == 1) {
    $where .= "obs_city='".$tokenresult['obs_city']."' AND ";
  }
  $where .= "1";

  $tokenfilterquery = mysqli_query($db, "SELECT obs_token,obs_categorie,obs_address_string,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE ".$where);

  while($tokenfilterresult = mysqli_fetch_array($tokenfilterquery)) {
    if((flatstring($tokenresult['obs_address_string']) == flatstring($tokenfilterresult['obs_address_string']) AND $filter['faddress']) OR
       (distance($tokenresult['obs_coordinates_lat'], $tokenresult['obs_coordinates_lon'], $tokenfilterresult['obs_coordinates_lat'], $tokenfilterresult['obs_coordinates_lon'], $unit = 'm') < $filter['distance'] AND $filter['fdistance'])) {
      $similar[] = $tokenfilterresult['obs_token'];
    }
  }
  return $similar;
}

/* https://www.drupal.org/forum/support/post-installation/2013-07-16/removing-emoji-code */
function removeEmoji($text) {

    $clean_text = "";

    // Match Emoticons
    $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
    $clean_text = preg_replace($regexEmoticons, '', $text);

    // Match Miscellaneous Symbols and Pictographs
    $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
    $clean_text = preg_replace($regexSymbols, '', $clean_text);

    // Match Transport And Map Symbols
    $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
    $clean_text = preg_replace($regexTransport, '', $clean_text);

    // Match flags (iOS)
    $regexFlag = '/[\x{1F1E0}-\x{1F1FF}]/u';
    $clean_text = preg_replace($regexFlag, '', $clean_text);


    return $clean_text;
}

function jsonError($prefix, $error_msg, $internal_code="Unknown", $http_status_code=500, $severity="FATAL")
{
  error_log('['. $severity . '] ' . $prefix.': '.$internal_code . ' - ' .$error_msg);
  if($severity == "FATAL") {
    $json = array("error" => array('status' => $http_status_code, "code" => $internal_code, "message" => $error_msg));
    http_response_code($http_status_code);
    echo json_encode($json, JSON_PRETTY_PRINT);
    exit();
  }
}

function getCategoriesList() {
  $categories_json = file_get_contents("https://vigilo-bf7f2.firebaseio.com/categorieslist.json");
  $categories_list = json_decode($categories_json,JSON_OBJECT_AS_ARRAY);
  return $categories_list;
}
function getCategorieName($catid) {
  $categories_list = getCategoriesList();
  foreach ($categories_list as $value) {
    if ($value['catid'] == $catid) {
      $categorie_string = $value['catname'];
    }
  }
  return $categorie_string;
}

// https://stackoverflow.com/questions/8995096/php-determine-visually-corrupted-images-yet-valid-downloaded-via-curl-with-gd
function isGoodImage($fn) {
  list($w,$h)=getimagesize($fn);
  if($w<50 || $h<50) return 0;
  $im=imagecreatefromstring(file_get_contents($fn));
  $grey=0;

  for($i=0;$i<5;++$i){
    for($j=0;$j<5;++$j){
      $x=$w-5+$i;
      $y=$h-5+$j;
      list($r,$g,$b)=array_values(imagecolorsforindex($im,imagecolorat($im,$x,$y)));
      if($r==$g && $g==$b && $b==128)
	++$grey;
    }
  }
  return $grey<12;
}
