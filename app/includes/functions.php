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

/* First $length characters of a UTF-8 string (mbstring is not always installed) */
function vigilo_truncate($text, $length)
{
    $text = (string) $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }
    return preg_match('/^.{0,' . intval($length) . '}/us', $text, $m) ? $m[0] : substr($text, 0, $length);
}

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
   
    foreach (glob(__DIR__ . "/../" . $config['DATA_PATH'] . "/caches/" . $token . "*") as $file) {
        unlink($file);
    }
}
function delete_map_cache($token)
{
    global $config;
    
    foreach (glob(__DIR__ . "/../" . $config['DATA_PATH'] . "/maps/" . $token . "*") as $file) {
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
 * @return array
 *	[success] => true/false
 *	[error] => message d'erreur
 *	[response] => Objet au format codebird, retour de l'API twitter
**/
function tweetToken($token ) {

	global $db;
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


/* Timestamp from the admin date (dd/mm/yyyy) and time (hh:mm) fields, False if invalid */
function parseAdminDateTime($date, $time)
{
    $datetime = DateTime::createFromFormat('!d/m/Y H:i', trim($date) . ' ' . trim($time));
    $errors   = DateTime::getLastErrors();
    if ($datetime === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        return False;
    }
    return $datetime->getTimestamp();
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

function generategroups($filter = array('distance' => 500, 'fdistance' => 0, 'fcategorie' => 0, 'faddress' => 0))
{
    global $db;
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

function sameas($token, $filter = array())
{
    global $db;

    $token       = mysqli_real_escape_string($db, $token);
    $tokenquery  = mysqli_query($db, "SELECT obs_categorie,obs_address_string,obs_city,obs_coordinates_lat,obs_coordinates_lon FROM obs_list WHERE obs_token='" . $token . "' LIMIT 1");
    $tokenresult = mysqli_fetch_array($tokenquery);
    
    $similar = array();
    if (!$tokenresult) {
        return $similar;
    }
    
    $where = '';
    if ($filter['fcategorie'] == 1) {
        $where .= "obs_categorie='" . intval($tokenresult['obs_categorie']) . "' AND ";
    }
    if ($filter['faddress'] == 1) {
        $where .= "obs_city='" . intval($tokenresult['obs_city']) . "' AND ";
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

/*
 * JSON document from a remote URL, cached on disk for $ttl seconds.
 * When the remote is unreachable, the last cached copy is used (even if older), so
 * that a GitHub outage does not break the panels.
 */
function getCachedRemoteJson($url, $cache_name, $ttl = 86400)
{
    global $config;

    $cache_dir  = dirname(__FILE__) . '/../' . $config['DATA_PATH'] . 'caches/';
    $cache_file = $cache_dir . 'remote_' . preg_replace('/[^a-z0-9_]/', '', $cache_name) . '.json';

    if (file_exists($cache_file) && filemtime($cache_file) > time() - $ttl) {
        $data = json_decode(file_get_contents($cache_file), true);
        if (is_array($data)) {
            return $data;
        }
    }

    $content = getWebContent($url);
    $data    = $content ? json_decode($content, true) : null;
    if (is_array($data)) {
        @file_put_contents($cache_file, $content, LOCK_EX);
        return $data;
    }

    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return array();
}

function getCategoriesList()
{
    global $config;
    return getCachedRemoteJson($config['CATEGORIES_NATIONAL_URL'], 'categories', 3600);
}

function getCategorieName($catid)
{
    $categorie_string = null;
    foreach (getCategoriesList() as $value) {
        if (isset($value['catid']) && $value['catid'] == $catid) {
            $categorie_string = $value['catname'];
        }
    }
    return $categorie_string;
}

/* Name of the instance in vigilo-conf (citylist.json) for a scope, used for links to the web app */
function getInstanceNameFromFirebase($scope)
{
    $citylist_list = getCachedRemoteJson('https://raw.githubusercontent.com/jesuisundesdeux/vigilo-conf/main/main/citylist.json', 'citylist', 86400);
    foreach ($citylist_list as $key => $value) {
        if (isset($value['scope']) && $value['scope'] == $scope) {
            return $key;
        }
    }
    return False;
}

/* Observations less than 200 m away from $issue, most recent first */
function findClosestIssues($db, $issue)
{
    $closestIssues = array();

    if (!$issue) {
        return $closestIssues;
    }

    $query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat, obs_coordinates_lon, obs_time, obs_token FROM obs_list WHERE obs_complete=1 ORDER BY obs_time DESC");
    while ($result_issues_coordinates = mysqli_fetch_array($query_issues_coordinates)) {
        if (distance(
                $issue['obs_coordinates_lat'],
                $issue['obs_coordinates_lon'],
                $result_issues_coordinates['obs_coordinates_lat'],
                $result_issues_coordinates['obs_coordinates_lon'],
                'm'
            ) < 200
            && $result_issues_coordinates['obs_token'] != $issue['obs_token']
        ) {
            $closestIssues[] = $result_issues_coordinates;
        }
    }

    return $closestIssues;
}

/* Marker colour of an observation according to its age */
function mapMarkerColor($time)
{
    $age = time() - $time;
    if ($age < 3600 * 24 * 30) {
        return 'db0000';
    } elseif ($age < 3600 * 24 * 30 * 6) {
        return 'db7800';
    }
    return 'a8a8a8';
}

/*
 * Map of the panel. Provider "mapquest" needs an API key (and a credit card at MapQuest,
 * issue #278); "osm" draws the map from OpenStreetMap tiles, without any key. "auto"
 * (default) uses MapQuest when a key is configured, OpenStreetMap otherwise.
 */
function GenerateMapForToken($token, $path)
{
    global $config, $db;

    $provider = 'auto';
    $tiles    = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    $query    = mysqli_query($db, "SELECT config_param, config_value FROM obs_config WHERE config_param IN ('vigilo_map_provider', 'vigilo_map_tiles_url')");
    while ($query && $row = mysqli_fetch_array($query)) {
        if ($row['config_param'] == 'vigilo_map_provider' && $row['config_value'] !== '') {
            $provider = $row['config_value'];
        } elseif ($row['config_param'] == 'vigilo_map_tiles_url' && $row['config_value'] !== '') {
            $tiles = $row['config_value'];
        }
    }
    $mapquest_key = isset($config['MAPQUEST_API']) ? $config['MAPQUEST_API'] : '';

    if ($provider == 'mapquest' || ($provider == 'auto' && $mapquest_key !== '')) {
        $config['MAP_ATTRIBUTION'] = '©MAPQUEST ©OPENSTREETMAP ©MAPBOX';
        return GenerateMapQuestForToken($token, $path, $mapquest_key);
    }
    $config['MAP_ATTRIBUTION'] = '© LES CONTRIBUTEURS D’OPENSTREETMAP';
    return GenerateOSMMapForToken($token, $path, $tiles);
}

function GenerateOSMMapForToken($token, $path, $tiles_url, $size = 390, $zoom = 17)
{
    global $db, $config;

    if (file_exists($path)) {
        return true;
    }

    $query_token   = mysqli_query($db, "SELECT obs_token, obs_coordinates_lat, obs_coordinates_lon FROM obs_list WHERE obs_token='" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
    $current_issue = mysqli_fetch_array($query_token);
    if (!$current_issue || !is_numeric($current_issue['obs_coordinates_lat']) || !is_numeric($current_issue['obs_coordinates_lon'])) {
        return false;
    }

    $lat = floatval($current_issue['obs_coordinates_lat']);
    $lon = floatval($current_issue['obs_coordinates_lon']);

    // Pixel position of the centre in the world map at this zoom
    $world  = 256 * pow(2, $zoom);
    $toPixel = function ($lat, $lon) use ($world) {
        $x = ($lon + 180) / 360 * $world;
        $s = sin(deg2rad($lat));
        $y = (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * $world;
        return array($x, $y);
    };
    list($cx, $cy) = $toPixel($lat, $lon);
    $left = $cx - $size / 2;
    $top  = $cy - $size / 2;

    $map = imagecreatetruecolor($size, $size);
    imagefill($map, 0, 0, imagecolorallocate($map, 230, 230, 230));

    $tiles_dir = dirname(__FILE__) . '/../' . $config['DATA_PATH'] . 'maps/tiles/';
    if (!file_exists($tiles_dir)) {
        @mkdir($tiles_dir, 0775, true);
    }

    $loaded = 0;
    for ($tx = (int) floor($left / 256); $tx <= (int) floor(($left + $size) / 256); $tx++) {
        for ($ty = (int) floor($top / 256); $ty <= (int) floor(($top + $size) / 256); $ty++) {
            $tile = loadMapTile($tiles_url, $zoom, $tx, $ty, $tiles_dir);
            if ($tile) {
                imagecopy($map, $tile, (int) round($tx * 256 - $left), (int) round($ty * 256 - $top), 0, 0, 256, 256);
                imagedestroy($tile);
                $loaded++;
            }
        }
    }
    if ($loaded == 0) {
        imagedestroy($map);
        return false;
    }

    // Nearby observations, then the observation itself on top
    $markers = array_slice(findClosestIssues($db, $current_issue), 0, 150);
    foreach (array_reverse($markers) as $marker) {
        list($mx, $my) = $toPixel(floatval($marker['obs_coordinates_lat']), floatval($marker['obs_coordinates_lon']));
        drawMapMarker($map, (int) round($mx - $left), (int) round($my - $top), mapMarkerColor($marker['obs_time']), 7);
    }
    drawMapMarker($map, (int) round($size / 2), (int) round($size / 2), 'ff0000', 11);

    $written = imagejpeg($map, $path, 90);
    imagedestroy($map);
    return $written;
}

function drawMapMarker($image, $x, $y, $hex_color, $radius)
{
    $color = imagecolorallocate($image, hexdec(substr($hex_color, 0, 2)), hexdec(substr($hex_color, 2, 2)), hexdec(substr($hex_color, 4, 2)));
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledellipse($image, $x, $y, 2 * $radius + 4, 2 * $radius + 4, $white);
    imagefilledellipse($image, $x, $y, 2 * $radius, 2 * $radius, $color);
}

/* One map tile, cached 30 days on disk (OpenStreetMap tile usage policy) */
function loadMapTile($tiles_url, $z, $x, $y, $tiles_dir)
{
    $max = pow(2, $z);
    if ($y < 0 || $y >= $max) {
        return false;
    }
    $x    = (($x % $max) + $max) % $max;
    $file = $tiles_dir . md5($tiles_url) . "_{$z}_{$x}_{$y}.png";

    if (!file_exists($file) || filemtime($file) < time() - 30 * 86400) {
        $url  = str_replace(array('{z}', '{x}', '{y}'), array($z, $x, $y), $tiles_url);
        $data = getWebContent($url);
        if ($data && @imagecreatefromstring($data) !== false) {
            @file_put_contents($file, $data, LOCK_EX);
        } elseif (!file_exists($file)) {
            return false;
        }
    }
    $tile = @imagecreatefromstring(file_get_contents($file));
    return $tile ? $tile : false;
}

function GenerateMapQuestForToken($token, $path, $mapquest_apikey)
{
    global $db;

    $size_w = 390;
    $size_h = 390;
    $size_zoom = $size_w . ',' . $size_h;
    $zoom = 17;
    
    $query_token  = mysqli_query($db, "SELECT obs_token, obs_coordinates_lat, obs_coordinates_lon FROM obs_list WHERE obs_token='" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
    $current_issue = mysqli_fetch_array($query_token);
    if (!$current_issue) {
        return false;
    }

    // mapquestapi limits requests size to 8 kbytes.
    // That's why we set a limit and select only 150 last markers.
    $closestIssues = array_slice(findClosestIssues($db, $current_issue), 0, 150);

    # Check closest issues
    $additionalmarkers = '';
    foreach($closestIssues as $closeIssue) {
        $additionalmarkers .= $closeIssue['obs_coordinates_lat'] . ',' . $closeIssue['obs_coordinates_lon'] . '|via-md-' . mapMarkerColor($closeIssue['obs_time']) . '||';
    }
    
    $url_zoom  = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . urlencode($mapquest_apikey)
        . '&center=' . $current_issue['obs_coordinates_lat'] . ',' . $current_issue['obs_coordinates_lon']
        . '&size=' . $size_zoom . '&zoom=' . $zoom
        . '&locations=' . $additionalmarkers . $current_issue['obs_coordinates_lat'] . ',' . $current_issue['obs_coordinates_lon']
        . '|marker-ff0000&type=hyb';
    
    if (!file_exists($path)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_zoom);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // catch output (do NOT print!)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $content_zoom = curl_exec($ch);
        
        $http_error_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        # Check the request went ok and Content-Type is a JPEG image
        if ($http_error_code != 200 || $content_type != 'image/jpeg') {
            // The URL holds the API key: never write it to the logs
            error_log('MapQuest map failed for ' . $token . ': HTTP_CODE = ' . $http_error_code . ' - Content-Type = ' . $content_type);
            curl_close($ch);
            return false;
        } else {
            file_put_contents($path, $content_zoom);
            curl_close($ch);
            return true;
        }
    }

    return true;
}


function getWebContent($url) {

  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_USERAGENT, "Vigilo-Backend/" . BACKEND_VERSION . " (+https://github.com/jesuisundesdeux/vigilo-backend)");
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  // A slow remote (GitHub, categories) must not hang the API or the admin
  curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($curl, CURLOPT_TIMEOUT, 10);
  curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
  $data = curl_exec($curl);
  $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  curl_close($curl);
  if ($data === false || $code >= 400) {
      return false;
  }
  return $data;
}
