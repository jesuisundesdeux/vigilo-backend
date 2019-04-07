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

require_once('./common.php');
header('BACKEND_VERSION: '.BACKEND_VERSION);

header('Content-Type: application/json');

require_once('./functions.php');
$BEFORE_TIME=time() - (2*24 * 60 * 60);
$where = '';
$added_fields='';

/* Filters */
# Categorie
if (isset($_GET['c']) and is_numeric($_GET['c'])) {
  $scategorie=intval($_GET['c']);
  $where .= ' AND obs_categorie = '.$scategorie;
}

# Last 24h
if (isset($_GET['t'])) {
  $where .= ' AND obs_time > '.$BEFORE_TIME;
}

# Status
if (isset($_GET['status']) and is_numeric($_GET['status'])) {
  $sstatus = intval($_GET['status']);
  $where .= 'AND obs_status = '.$sstatus;
}

# Token
if (isset($_GET['token']) AND !empty($_GET['token'])) {
  $stoken = mysqli_real_escape_string($db,$_GET['token']);
  $where .= " AND obs_token = '".$stoken."'";
}

# Scope 
if(isset($_GET['scope']) and !empty($_GET['scope'])) {
  $scope = mysqli_real_escape_string($db,$_GET['scope']);
  if($scope != '34_montpellier') {
    $where .= " AND obs_scope = '".$scope."'";
  }
}

# Count
if (isset($_GET['count']) and is_numeric($_GET['count'])) {
  $limit = 'LIMIT '.intval($_GET['count']);

  if (isset($_GET['offset']) and is_numeric($_GET['offset'])) {
    $offset = intval($_GET['offset']);
    $limit .= ' OFFSET '.$offset;
  }

}
else {
  $limit = '';
}

$query = mysqli_query($db, "SELECT obs_token,
                                   obs_coordinates_lat,
                                   obs_coordinates_lon,
                                   obs_address_string,
                                   obs_comment,
                                   obs_time,
                                   obs_categorie,
                                   ".$added_fields."
                                   obs_approved 
                             FROM obs_list
                             WHERE obs_complete=1 
                             AND (obs_approved=0 OR obs_approved=1)
                             ".$where." 
                             ORDER BY obs_time DESC 
                             ".$limit);
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
