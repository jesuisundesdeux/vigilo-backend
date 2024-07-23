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
 * TODO: adjust to mastodon
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
function tweet($social_ids, $text, $image = NULL ) {

    \Codebird\Codebird::setConsumerKey($social_ids['consumer'], $social_ids['consumersecret']);
    $cb = \Codebird\Codebird::getInstance();
    $cb->setToken($social_ids['accesstoken'], $social_ids['accesstokensecret']);
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
 * post_mastodon
 * 
 * Posts the given image and text to the given Mastodon instance.
 * Does so using only REST calls, no third-party libraries.
 * @param array $social_ids
 *     array containing the Mastodon instance URL and the access token
 * @param string $text
 *    the text to post
 * @param string $image
 *   the path to the image to post, or URL
 */
function post_mastodon($social_ids, $text, $image = NULL, $caption = NULL) {
    $instance = $social_ids['api_url'];
    $token = $social_ids['accesstoken'];
    $params = array(
        'status' => $text,
    );
    if (!empty($image)) {
        // need to make a call to the media upload api first, to get the media id
        $ch = curl_init($instance . '/api/v2/media');

        // the "file" parameter holds "The file to be attached, encoded using
        // multipart form data. The file must have a MIME type."
        // so we need to get the MIME type of the image
        $mime = mime_content_type($image);
        $media_params = array(
            'file' => new CURLFile($image, $mime, basename($image)),
        );
        // if a caption is given, pass it as "description"
        if (!empty($caption)) {
            $media_params['description'] = $caption;
        }
        // make the request
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $media_params);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token,
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // if the media upload was successful, we get the media id
        if ($http_status == 200) {
            $media_id = json_decode($response)->id;
            $params['media_ids'] = array($media_id);
        } else {
            return (object) array(
                'httpstatus' => $http_status,
                'response' => $response,
            );
        }
    }
    $status_curl = curl_init($instance . '/api/v1/statuses');
    curl_setopt_array($status_curl, array(
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_RETURNTRANSFER => true,
    ));
    curl_setopt($status_curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($status_curl);
    $http_status = curl_getinfo($status_curl, CURLINFO_HTTP_CODE);
    curl_close($status_curl);
    return (object) array(
        'httpstatus' => $http_status,
        'response' => $response,
    );
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

    $query_text = "SELECT obs_scopes.scope_socialmediaaccountid,
		  obs_scopes.scope_socialcontent,
		  obs_social_media_accounts.ta_consumer,
		  obs_social_media_accounts.ta_consumersecret,
		  obs_social_media_accounts.ta_accesstoken,
		  obs_social_media_accounts.ta_accesstokensecret,
		  obs_social_media_accounts.ta_type,
          obs_social_media_accounts.ta_api_url
	   FROM obs_scopes, obs_social_media_accounts 
	   WHERE obs_scopes.scope_socialmediaaccountid= obs_social_media_accounts.ta_id 
	     AND obs_scopes.scope_name = '" . $scope . "'";
	$scope_query  = mysqli_query($db, $query_text);
	$scope_result = mysqli_fetch_array($scope_query);

	if ($scope_result['ta_type'] == 'twitter' && (empty($scope_result['ta_consumer']) || empty($scope_result['ta_consumersecret']) || empty($scope_result['ta_accesstoken']) || empty($scope_result['ta_accesstokensecret']))) {
		$return['success'] = false ;
		$return['error'] = "Empty twitter secrets on scope." ;
        return $return;
    }

    if ($scope_result['ta_type'] == 'mastodon' && (empty($scope_result['ta_accesstoken']) || empty($scope_result['ta_api_url']))) {
        $return['success'] = false ;
        $return['error'] = "Empty mastodon secrets on scope." ;
        return $return;
    }

		$social_ids   = array(
			"consumer" => $scope_result['ta_consumer'],
			"consumersecret" => $scope_result['ta_consumersecret'],
			"accesstoken" => $scope_result['ta_accesstoken'],
			"accesstokensecret" => $scope_result['ta_accesstokensecret'],
            "type" => $scope_result['ta_type'],
            "api_url" => $scope_result['ta_api_url']
		);
		$tweet_content = $scope_result['scope_socialcontent'];
		if ( empty($tweet_content) ) {
			$tweet_content = "" ;
		}

		/* Don't tweet observations if they are more than N-hours old */
		if ($time > (time() - 3600 * $config['APPROVE_SOCIAL_MEDIA_EXPTIME'] )) {
			$tweet_content = str_replace('[COMMENT]', $comment, $tweet_content);
			$tweet_content = str_replace('[TOKEN]', $token, $tweet_content);
			$tweet_content = str_replace('[COORDINATES_LON]', $coordinates_lon, $tweet_content);
			$tweet_content = str_replace('[COORDINATES_LAT]', $coordinates_lat, $tweet_content);
			$tweet_content = str_replace('[CATEGORY]', $categorie, $tweet_content);
			$tweet_content = str_replace('[CITY]', $cityname, $tweet_content);
			$tweet_content = str_replace('[CITYHASHTAG]', $citynamehashtag, $tweet_content);

            // post to twitter or mastodon
            $image_url = $config['HTTP_PROTOCOL'].'://'. $config['URLBASE'] .'/generate_panel.php?token='.$token;
            if ($social_ids['type'] == 'twitter') {
                // on vérifie la bibliothèque
                $return['response'] = tweet($social_ids, $tweet_content, $image_url);
            } else {
                // mastodon needs the images as a local file.
                // this call gives us a gd image file as returned by gd, as well as the corresponding file name
                $image_data = generate_and_save_panel($token, NULL, NULL, NULL, 'POST_MASTODON');
                $return['response'] = post_mastodon($social_ids, $tweet_content, $image_data['filename'], 'sommaire de l\'observation');
            }
			if ( $return['response']->httpstatus == 200 ) {
				$return['success'] = true ;
				$return['error'] = "" ;
			}
			else {
				$return['success'] = false ;
				$return['error'] = "Erreur ".$return['response']->httpstatus ;
			}

			echo '<div class="alert alert-success" role="alert">Puet pour <strong>'.$obsid.'</strong> parti</div>';

		} else {
			$return['success'] = false ;
			$return['error'] = "Token : " . $token . " older than " . $config['APPROVE_SOCIAL_MEDIA_EXPTIME'] . "h. We won't tweet it." ;
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

function findClosestIssues($db, $issue)
{
    $closestIssues = [];
    
    $query_issues_coordinates = mysqli_query($db, "SELECT obs_coordinates_lat, obs_coordinates_lon, obs_time, obs_token FROM obs_list ORDER BY obs_time DESC");
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
            $additionalmarkers[] = $result_issues_coordinates;
        }
    }

    return $closestIssues;
}

function GenerateMapQuestForToken($token, $path, $mapquest_apikey)
{
    global $db;

    $size_w = 390;
    $size_h = 390;
    $size_zoom = $size_w . ',' . $size_h;
    $zoom = 17;
    $color_recent = 'db0000';
    $color_month = 'db7800';
    $color_old = 'a8a8a8';
    
    $query_token  = mysqli_query($db, 'SELECT obs_token, obs_coordinates_lat, obs_coordinates_lon FROM obs_list WHERE obs_token="' . $token . '" LIMIT 1');
    $current_issue = mysqli_fetch_array($query_token);

    // mapquestapi limits requests size to 8 kbytes.
    // That's why we set a limit and select only 150 last markers.
    $closestIssues = array_slice(findClosestIssues($db, $current_issue), 0, 150);

    # Check closest issues
    $additionalmarkers = '';
    foreach($closestIssues as $closeIssue) {
        $age = time() - $closeIssue['obs_time'];
        if ($age < 3600 * 24 * 30) {
            $color = $color_recent;
        } elseif ($age < 3600 * 24 * 30 * 6) {
            $color = $color_month;
        } else {
            $color = $color_old;
        }

        $additionalmarkers .= $closeIssue['obs_coordinates_lat'] . ',' . $closeIssue['obs_coordinates_lon'] . '|via-md-' . $color . '||';
    }
    
    $url_zoom  = 'https://www.mapquestapi.com/staticmap/v5/map?key=' . $mapquest_apikey
        . '&center=' . $current_issue['obs_coordinates_lat'] . ',' . $current_issue['obs_coordinates_lon']
        . '&size=' . $size_zoom . '&zoom=' . $zoom
        . '&locations=' . $additionalmarkers . $current_issue['obs_coordinates_lat'] . ',' . $current_issue['obs_coordinates_lon']
        . '|marker-ff0000&type=hyb';
    
    if (!file_exists($path)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_zoom);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // catch output (do NOT print!)
        $content_zoom = curl_exec($ch);
        
        $http_error_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        # Check the request went ok and Content-Type is a JPEG image
        if ($http_error_code != 200 || $content_type != 'image/jpeg') {
            error_log(
                'Unexpected HTTP result HTTP_CODE = ' . $http_error_code .
                ' - Url = ' . $url_zoom .
                ' - Content-Type = ' . $content_type
            );
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
  curl_setopt($curl, CURLOPT_USERAGENT, "User-Agent: Vigilo Backend Version/" . BACKEND_VERSION);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  $data = curl_exec($curl);
  return $data;
}


function generate_and_save_panel($token, $requested_size, $secretid, $key, $error_prefix) {

    require_once("images.php");
    require_once("handle.php");
    require_once("common.php");

    global $acls;
	global $db;
	global $config;

    $query      = mysqli_query($db, "SELECT * FROM obs_config
                                WHERE config_param='vigilo_panel'
                                LIMIT 1");
    $result     = mysqli_fetch_array($query);
    $panel_path = $result['config_value'];

    $parentDir = dirname(__DIR__);
    $panel_file = $parentDir . '/panels/' . $panel_path . '/panel.php';
    if (file_exists($panel_file)) {
        require_once($panel_file);
    } else {
        die('Panel not exists');
    }

    $project_root    = dirname(__DIR__);
    $caches_path     = "$project_root" . '/' . $config['DATA_PATH'] . "caches/";
    $images_path     = "$project_root" . '/' . $config['DATA_PATH'] . "images/";
    $maps_path       = "$project_root" . '/' . $config['DATA_PATH'] . "maps/";
    $MAX_IMG_SIZE    = 1024; // For limit attack

    if ($requested_size != Null && $requested_size <= $MAX_IMG_SIZE) {
        $resize_width = $requested_size;
        $img_filename = $caches_path . $token . '_w' . $resize_width . '.jpg';
    } else {
        $resize_width    = $MAX_IMG_SIZE; // default width
        $img_filename = $caches_path . $token . '_full.jpg';
    }

    ## Use caches if available
    if (file_exists($img_filename) && !getrole($key, $acls) == "admin" && !getrole($key, $acls) == "moderator") {
        $image = imagecreatefromjpeg($img_filename);
        imagejpeg($image);
        return;
    }

    # Get issue information
    $query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");

    if (mysqli_num_rows($query) != 1) {
        jsonError($error_prefix, "Token : " . $token . " not found.", "TOKENNOTFOUND", 404);
    }

    $result            = mysqli_fetch_array($query);
    $coordinates_lat   = $result['obs_coordinates_lat'];
    $coordinates_lon   = $result['obs_coordinates_lon'];
    $street_name       = $result['obs_address_string'];
    $comment           = $result['obs_comment'];
    $categorie_id      = $result['obs_categorie'];
    $resolution_status = getResolutionStatus($result['obs_id']);
    $categorie_string  = getCategorieName($categorie_id);

    if (!empty($result['obs_city']) && $result['obs_city'] != 0) {
        $cityquery  = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_id='" . $result['obs_city'] . "' LIMIT 1");
        $cityresult = mysqli_fetch_array($cityquery);
        $street_name .= ', ' . $cityresult['city_name'];
    } elseif (!empty($result['obs_cityname'])) {
        $street_name .= ', ' . $result['obs_cityname'];
    }

    if (!isset($categorie_string)) {
        jsonError($error_prefix, "unknown categorie_id: ' . $categorie_id", "UNKNOWCATEGORIE", 500);
    }

    $time = $result['obs_time'];
    $date = date('d/m/Y H:i', $time);

    $approved = $result['obs_approved'];
    if ($secretid == $result['obs_secretid'] || getrole($key, $acls) == "admin" || getrole($key, $acls) == "moderator") {
        $AdminOrAuthor = True;
    } else {
        $AdminOrAuthor = False;
    }

    $filepath = $images_path . $token . '.jpg';

    # Image is pixelated until approved by a moderator
    if ($approved != 1 && !$AdminOrAuthor && $resize_width > 300) {
        $photo = pixalize($filepath);
    } else {
        $photo = imagecreatefromjpeg($filepath); // issue photo
    }

    $map_file_path = $maps_path . $token . '_zoom.jpg';
    $res = GenerateMapQuestForToken($token, $map_file_path, $config['MAPQUEST_API']);
    if (!$res) {
        // Use default place holder picture instead of crashing
        $map_file_path = "$project_root/panel_components/map_error.jpeg";
    }

    $map = imagecreatefromjpeg($map_file_path);

    if (!$map) {
        jsonError($error_prefix, "Map for : " . $token . " can not be created.", "MAPNOTCREATED", 500);
    }

    $image = GeneratePanel($photo, $map, $comment, $street_name, $token, $categorie_string, $date, $statusobs);

    # save the resulting image so that the next call is faster
    if ($resize_width == $MAX_IMG_SIZE && !$AdminOrAuthor) {
        imagejpeg($image, $img_filename);
    } else {
        $imageresized = resizeImage($image, $resize_width, $MAX_IMG_SIZE);
        imagejpeg($imageresized, $img_filename);
    }

    // return image and filename as name array
    return array(
        'image' => $image,
        'filename' => $img_filename
    );
}