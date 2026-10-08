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
