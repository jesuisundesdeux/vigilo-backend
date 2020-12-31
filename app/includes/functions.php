<?php
/*
Copyright (C) 2020 Velocité Montpellier

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

function tokenGenerator($length)
{
    $bytes = random_bytes($length);
    return strtoupper(bin2hex($bytes));
}


// https://numa-bord.com/miniblog/php-calcul-de-distance-entre-2-coordonnees-gps-latitude-longitude/
function distance($lat1, $lng1, $lat2, $lng2, $unit = 'k')
{
    $earth_radius = 6378137; // Terre = sphère de 6378km de rayon
    $rlo1         = deg2rad($lng1);
    $rla1         = deg2rad($lat1);
    $rlo2         = deg2rad($lng2);
    $rla2         = deg2rad($lat2);
    $dlo          = ($rlo2 - $rlo1) / 2;
    $dla          = ($rla2 - $rla1) / 2;
    $a            = (sin($dla) * sin($dla)) + cos($rla1) * cos($rla2) * (sin($dlo) * sin($dlo));
    $d            = 2 * atan2(sqrt($a), sqrt(1 - $a));
    //
    $meter        = ($earth_radius * $d);
    if ($unit == 'k') {
        return $meter / 1000;
    }
    return $meter;
}

function get_data_from_gps_coordinates($lat, $lon)
{
    // MAX 1 request per second
    // https://operations.osmfoundation.org/policies/nominatim/
    $url       = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . $lat . '&lon=' . $lon;
    $resp_json = getWebContent($url);
    $resp      = json_decode($resp_json, true);
    return $resp;
}

function delete_token_cache($token)
{
    global $config;
    
    foreach (glob(__DIR__ . "/" . $config['DATA_PATH'] . "../caches/" . $token . "*") as $file) {
        unlink($file);
    }
}
function delete_map_cache($token)
{
    global $config;
    
    foreach (glob(__DIR__ . "/" . $config['DATA_PATH'] . "../maps/" . $token . "*") as $file) {
        unlink($file);
    }
}
/**
 * tweet
 *
 * Poste un tweet comprenant du texte et une image ; remplace tweet($text, $image, $twitter_ids)
 *
 * @param array $twitter_ids
 *      ensemble des identifiants consumer, consumersecret, accesstoken, accesstokensecret
 * @param string $text
 *      texte du tweet
 * @param string $image
 *      adresse web d'une image sous forme http... 
 * @return obj
 *	objet au format codebird comprenant le code erreur/succès httpstatus de l'API twitter
**/
function tweet($twitter_ids, $text, $image = NULL ) {

    \Codebird\Codebird::setConsumerKey($twitter_ids['consumer'], $twitter_ids['consumersecret']);
    $cb = \Codebird\Codebird::getInstance();
    $cb->setToken($twitter_ids['accesstoken'], $twitter_ids['accesstokensecret']);
    // $text = urlencode($text) ; // n'est pas nécessaire
    if ( !empty($image) ) { 
    	$reply   = $cb->media_upload(array(
        	'media' => $image
    	));
    	$mediaID = $reply->media_id_string;
    
        $params = array(
		'status' => $text,
		'media_ids' => $mediaID
    	);
    }
    else {
        $params = array(
		'status' => $text
    	);
    }
    $reply  = $cb->statuses_update($params);
    
    return $reply ;
}


/**
 * tweetToken
 *
 * Poste un tweet au format personnalisé à partir d'un token
 *
 * @param string $token
 *      identifiant du token à twitter
 * @param ressource $db
 *	objet valide qui représente la connexion au serveur MySQL
 * @return array
 *	[success] => true/false
 *	[error] => message d'erreur
 *	[response] => Objet au format codebird, retour de l'API twitter
**/
function tweetToken( $db , $token ) {

	global $config;

	// on pourrait faire un test sur format de token

	if ( $db == false ) {
		$return['success'] = false ;
		$return['error'] = "Erreur MySQL." ;
		return $return ;
	}

	// récupère les infos du token ds la base
	$checktoken_query = mysqli_query($db, "SELECT obs_token,obs_scope,obs_comment,obs_time,obs_coordinates_lat,obs_coordinates_lon,obs_categorie,obs_city,obs_cityname,obs_address_string FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
	$checktoken_result = mysqli_fetch_array($checktoken_query);
	$comment           = $checktoken_result['obs_comment'];
	$time              = $checktoken_result['obs_time'];
	$coordinates_lat   = $checktoken_result['obs_coordinates_lat'];
	$coordinates_lon   = $checktoken_result['obs_coordinates_lon'];
	$scope             = $checktoken_result['obs_scope'];
	$categorie         = getCategorieName($checktoken_result['obs_categorie']);
	
	$cityname = "";
	if (!empty($checktoken_result['obs_city']) && $checktoken_result['obs_city'] != 0) {
		$cityquery  = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_id='" . $checktoken_result['obs_city'] . "' LIMIT 1");
		$cityresult = mysqli_fetch_array($cityquery);
		$cityname   = $cityresult['city_name'];
	} elseif (!empty($checktoken_result['obs_cityname'])) {
		$cityname = $checktoken_result['obs_cityname'];
	} elseif (preg_match('/^(?:[^,]*),([^,]*)$/', $checktoken_result['obs_address_string'], $cityInadress)) {
		if (count($cityInadress) == 2) {
			$cityname = trim($cityInadress[1]);
		}	
	}
	// crée le hashtag CITYHASHTAG
	$citynamehashtag = "#".str_replace( array("-"," ") , "" , $cityname ) ;

	$scope_query  = mysqli_query($db, "SELECT obs_scopes.scope_twitteraccountid,
		  obs_scopes.scope_twittercontent,
		  obs_twitteraccounts.ta_consumer,
		  obs_twitteraccounts.ta_consumersecret,
		  obs_twitteraccounts.ta_accesstoken,
		  obs_twitteraccounts.ta_accesstokensecret  
	   FROM obs_scopes, obs_twitteraccounts 
	   WHERE obs_scopes.scope_twitteraccountid= obs_twitteraccounts.ta_id 
	     AND obs_scopes.scope_name = '" . $scope . "'");
	$scope_result = mysqli_fetch_array($scope_query);

	if (!empty($scope_result['ta_consumer']) && !empty($scope_result['ta_consumersecret']) && !empty($scope_result['ta_accesstoken']) && !empty($scope_result['ta_accesstokensecret'])) {

		$twitter_ids   = array(
			"consumer" => $scope_result['ta_consumer'],
			"consumersecret" => $scope_result['ta_consumersecret'],
			"accesstoken" => $scope_result['ta_accesstoken'],
			"accesstokensecret" => $scope_result['ta_accesstokensecret']
		);
		$tweet_content = $scope_result['scope_twittercontent'];

		if ( empty($tweet_content) ) {
			$tweet_content = "" ;
		}

		/* Don't tweet observations if they are more than N-hours old */
		if ($time > (time() - 3600 * $config['APPROVE_TWITTER_EXPTIME'] )) {
			$tweet_content = str_replace('[COMMENT]', $comment, $tweet_content);
			$tweet_content = str_replace('[TOKEN]', $token, $tweet_content);
			$tweet_content = str_replace('[COORDINATES_LON]', $coordinates_lon, $tweet_content);
			$tweet_content = str_replace('[COORDINATES_LAT]', $coordinates_lat, $tweet_content);
			$tweet_content = str_replace('[CATEGORY]', $categorie, $tweet_content);
			$tweet_content = str_replace('[CITY]', $cityname, $tweet_content);
			$tweet_content = str_replace('[CITYHASHTAG]', $citynamehashtag, $tweet_content);

			$return['response'] = tweet($twitter_ids, $tweet_content, $config['HTTP_PROTOCOL'].'://'. $config['URLBASE'] .'/generate_panel.php?token='.$token );
			if ( $return['response']->httpstatus == 200 ) {
				$return['success'] = true ;
				$return['error'] = "" ;
			}
			else {
				$return['success'] = false ;
				$return['error'] = "Erreur ".$return['response']->httpstatus ;
			}

			//echo '<div class="alert alert-success" role="alert">Twitt <strong>'.$obsid.'</strong> parti</div>';

		} else {
			$return['success'] = false ;
			$return['error'] = "Token : " . $token . " older than " . $config['APPROVE_TWITTER_EXPTIME'] . "h. We won't tweet it." ;
		}
	} else {
		$return['success'] = false ;
		$return['error'] = "Empty Twitter informations on scope." ;
	}
    return $return ;
}


function getrole($privatekey, $acls)
{
    foreach ($acls as $key => $value) {
        if (!empty($privatekey) && in_array($privatekey, $value)) {
            return $key;
        }
    }
    return False;
}

function flatstring($string)
{
    return str_replace(' ', '', str_replace('-', '', strtolower(trim($string))));
}

function generategroups($db, $filter = array('distance' => 500, 'fdistance' => 0, 'fcategorie' => 0, 'faddress' => 0))
{
    $query  = mysqli_query($db, "SELECT * FROM obs_list");
    $groups = array();
    while ($result = mysqli_fetch_array($query)) {
        $in_group        = 0;
        $token           = $result['obs_token'];
        $categorie       = $result['obs_categorie'];
        $coordinates_lat = $result['obs_coordinates_lat'];
        $coordinates_lon = $result['obs_coordinates_lon'];
        $address         = $result['obs_address_string'];
        $cityid          = $result['obs_city'];
        $citycondition   = True;
        
        foreach ($groups as $key => $value) {
            if ($cityid != "0") {
                if ($cityid == $value['cityid']) {
                    $citycondition = True;
                } else {
                    $citycondition = False;
                }
            }
            
            if ($value['categorie'] == $categorie OR $filter['fcategorie'] == 0) {
                if (((flatstring($value['address_string']) == flatstring($address) && $citycondition) OR $filter['faddress'] == 0) OR (distance($value['coordinates_lat'], $value['coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < $filter['distance'] OR $filter['fdistance'] == 0)) {
                    $groups[$key]['tokens'][] = $token;
                    $groups[$key]['count']++;
                    $in_group = 1;
                    break;
                }
            }
        }
        if ($in_group == 0) {
            $groups[] = array(
                "cityid" => $cityid,
                "categorie" => $categorie,
                'address_string' => $address,
                'coordinates_lat' => $coordinates_lat,
                'coordinates_lon' => $coordinates_lon,
                'tokens' => array(
                    $token
                ),
                'count' => 1
            );
        }
    }
    return $groups;
}

function sameas($db, $token, $filter = array())
{
    $tokenquery  = mysqli_query($db, "SELECT obs_categorie,obs_address_string,obs_city,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
    $tokenresult = mysqli_fetch_array($tokenquery);
    
    $similar = array();
    
    $where = '';
    if ($filter['fcategorie'] == 1) {
        $where .= "obs_categorie='" . $tokenresult['obs_categorie'] . "' AND ";
    }
    if ($filter['faddress'] == 1) {
        $where .= "obs_city='" . $tokenresult['obs_city'] . "' AND ";
    }
    $where .= "1";
    
    $tokenfilterquery = mysqli_query($db, "SELECT obs_token,obs_categorie,obs_address_string,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE " . $where);
    
    while ($tokenfilterresult = mysqli_fetch_array($tokenfilterquery)) {
        if ((flatstring($tokenresult['obs_address_string']) == flatstring($tokenfilterresult['obs_address_string']) AND $filter['faddress']) OR (distance($tokenresult['obs_coordinates_lat'], $tokenresult['obs_coordinates_lon'], $tokenfilterresult['obs_coordinates_lat'], $tokenfilterresult['obs_coordinates_lon'], $unit = 'm') < $filter['distance'] AND $filter['fdistance'])) {
            $similar[] = $tokenfilterresult['obs_token'];
        }
    }
    return $similar;
}

/* https://www.drupal.org/forum/support/post-installation/2013-07-16/removing-emoji-code */
function removeEmoji($text)
{
    
    $clean_text = "";
    
    // Match Emoticons
    $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
    $clean_text     = preg_replace($regexEmoticons, '', $text);
    
    // Match Miscellaneous Symbols and Pictographs
    $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
    $clean_text   = preg_replace($regexSymbols, '', $clean_text);
    
    // Match Transport And Map Symbols
    $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
    $clean_text     = preg_replace($regexTransport, '', $clean_text);
    
    // Match flags (iOS)
    $regexFlag  = '/[\x{1F1E0}-\x{1F1FF}]/u';
    $clean_text = preg_replace($regexFlag, '', $clean_text);
    
    
    return $clean_text;
}

function jsonError($prefix, $error_msg, $internal_code = "Unknown", $http_status_code = 500, $severity = "FATAL")
{
    error_log('[' . $severity . '] ' . $prefix . ': ' . $internal_code . ' - ' . $error_msg);
    if ($severity == "FATAL") {
        $json = array(
            "error" => array(
                'status' => $http_status_code,
                "code" => $internal_code,
                "message" => $error_msg
            )
        );
        http_response_code($http_status_code);
        echo json_encode($json, JSON_PRETTY_PRINT);
        exit();
    }
}

function getCategoriesList()
{
    global $config;
    $categories_json = getWebContent($config['CATEGORIES_NATIONAL_URL']);
    $categories_list = json_decode($categories_json, JSON_OBJECT_AS_ARRAY);
    return $categories_list;
}

function getCategorieName($catid)
{
    global $config;

    $categories_json = getWebContent($config['CATEGORIES_NATIONAL_URL']);
    $categories_list = json_decode($categories_json, JSON_OBJECT_AS_ARRAY);
    foreach ($categories_list as $value) {
        if ($value['catid'] == $catid) {
            $categorie_string = $value['catname'];
        }
    }
    return $categorie_string;
}


function getInstanceNameFromFirebase($scope)
{
    $citylist_list = json_decode($citylist_json, JSON_OBJECT_AS_ARRAY);
    foreach ($citylist_list as $key => $value) {
        if ($value['scope'] == $scope) {
            return $key;
        }
    }
    return False;
}

// https://stackoverflow.com/questions/8995096/php-determine-visually-corrupted-images-yet-valid-downloaded-via-curl-with-gd
function isGoodImage($fn)
{
    list($w, $h) = getimagesize($fn);
    if ($w < 50 || $h < 50)
        return 0;
    $im   = imagecreatefromstring(file_get_contents($fn));
    $grey = 0;
    
    for ($i = 0; $i < 5; ++$i) {
        for ($j = 0; $j < 5; ++$j) {
            $x = $w - 5 + $i;
            $y = $h - 5 + $j;
            list($r, $g, $b) = array_values(imagecolorsforindex($im, imagecolorat($im, $x, $y)));
            if ($r == $g && $g == $b && $b == 128)
                ++$grey;
        }
    }
    return $grey < 12;
}

function GenerateMapQuestForToken($db, $token, $mapquest_apikey, $size_w = 390, $size_h = 390, $zoom = 17, $map_file_path = 'DEFAULT', $color_recent = 'db0000', $color_month = 'db7800', $color_old = 'a8a8a8')
{
    
    global $config;
    
    if ($map_file_path == 'DEFAULT') {
        $map_download_path_zoom = $config['DATA_PATH'] . 'maps/' . $token . '_zoom.jpg';
    } else {
        $map_download_path_zoom = $map_file_path;
    }
    # Check closest issues
    $additionalmarkers = '';
    $count             = 0;
    
    $query_token  = mysqli_query($db, 'SELECT obs_coordinates_lat, obs_coordinates_lon FROM obs_list WHERE obs_token="' . $token . '" LIMIT 1');
    $result_token = mysqli_fetch_array($query_token);
    
    $query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat, obs_coordinates_lon, obs_time, obs_token FROM obs_list ORDER BY obs_time DESC");
    while ($result_issues_coordinates = mysqli_fetch_array($query_issues_coordinates)) {
        if (distance($result_token['obs_coordinates_lat'], $result_token['obs_coordinates_lon'], $result_issues_coordinates['obs_coordinates_lat'], $result_issues_coordinates['obs_coordinates_lon'], 'm') < 200 && $result_issues_coordinates['obs_token'] != $token) {
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
    
    $size_zoom = $size_w . ',' . $size_h;
    $url_zoom  = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquest_apikey . '&center=' . $result_token['obs_coordinates_lat'] . ',' . $result_token['obs_coordinates_lon'] . '&size=' . $size_zoom . '&zoom=' . $zoom . '&locations=' . $additionalmarkers . $result_token['obs_coordinates_lat'] . ',' . $result_token['obs_coordinates_lon'] . '|marker-ff0000&type=hyb';
    
    if (!file_exists($map_download_path_zoom)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_zoom);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // catch output (do NOT print!)
        $content_zoom = curl_exec($ch);
        
        $http_error_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        # Check the request went ok and Content-Type is a JPEG image
        if ($http_error_code != 200 || $content_type != 'image/jpeg') {
            // Use default place holder picture instead of crashing
            $map_download_path_zoom = "${cwd}/panel_components/map_error.jpeg";
            error_log('Unexpected HTTP result HTTP_CODE = ' . $http_error_code . ' - Content-Type = ' . $content_type);
            curl_close($ch);
            return False;
        } else {
            file_put_contents($map_download_path_zoom, $content_zoom);
            curl_close($ch);
            return True;
        }
    }
}


function getWebContent($url) {

  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_USERAGENT, "User-Agent: Vigilo Backend Version/" . BACKEND_VERSION);
   
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  $data = curl_exec($curl);
  return $data;
}

