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
$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");
require_once("${cwd}/includes/functions.php");

header('BACKEND_VERSION: '.BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (isset($_GET['scope'])) {
  $scope = mysqli_real_escape_string($db, $_GET['scope']);
} else {
  jsonError('GET_SCOPE', 'Scope is not defined');
  return;
}

$query = mysqli_query($db, "SELECT * FROM obs_scopes
                            WHERE scope_name='".$scope."'
                            LIMIT 1");

if (mysqli_num_rows($query) == 0) {
  jsonError('GET_SCOPE', 'Scope '.$scope.' does not exist');
  return;
}

$result = mysqli_fetch_array($query);

$query_cities = mysqli_query($db, "SELECT * FROM obs_cities
                                   WHERE city_scope='".$result['scope_id']."'");
$json_cities = array();

while ($result_cities = mysqli_fetch_array($query_cities)) {
  $json_city = array(
      "id" => $result_cities['city_id'],
      "name" => $result_cities['city_name'],
      "postcode" => $result_cities['city_postcode'],
      "area" => $result_cities['city_area'],
      "population" => $result_cities['city_population'],
      "website" => $result_cities['city_website']
  );
  $json_cities[] = $json_city;
}

$json = array(
        'display_name' => $result['scope_display_name'],
        'department' => $result['scope_department'],
        'coordinate_lat_min' => $result['scope_coordinate_lat_min'],
        'coordinate_lat_max' => $result['scope_coordinate_lat_max'],
        'coordinate_lon_min' => $result['scope_coordinate_lon_min'],
        'coordinate_lon_max' => $result['scope_coordinate_lon_max'],
        'map_center_string' => $result['scope_map_center_string'],
        'map_zoom' => $result['scope_map_zoom'],
        'contact_email' => $result['scope_contact_email'],
        'tweet_content' => $result['scope_sharing_content_text'],
        'twitter' => $result['scope_twitter'],
        'map_url' => $result['scope_umap_url'],
        'backend_version' => BACKEND_VERSION,
        'cities' => $json_cities);

echo json_encode($json, JSON_PRETTY_PRINT);

