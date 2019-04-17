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

require_once('lib/codebird-php/codebird.php');

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

function delete_token_cache($token) {
    foreach(glob(__DIR__."/caches/".$token."*") as $file) {
        unlink($file);
    }
}
function delete_map_cache($token) {
    foreach(glob(__DIR__."/maps/".$token."*") as $file) {
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
      if($value['categorie'] == $categorie OR $filter['fcategorie'] == 0) {
        if((str_replace(' ','',$value['address_string']) == str_replace(' ','',$address) OR $filter['faddress'] == 0) OR
        (distance($value['coordinates_lat'], $value['coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < $filter['distance'] OR $filter['fdistance'] == 0)) {
  	      $groups[$key]['tokens'][] = $token;
  	      $groups[$key]['count']++;
  	      $in_group = 1;
	      break;
        }
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


