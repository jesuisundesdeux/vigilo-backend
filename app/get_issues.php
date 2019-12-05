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
require_once("${cwd}/includes/handle.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Access-Control-Allow-Origin: *');

class GetIssues
{

  private $format_list = array(
    'csv' => 'text/csv',
    'json' => 'application/json; charset=utf-8',
    'geojson' => 'application/json; charset=utf-8'
  );

  protected $format = 'json';
  protected $formatheader = 'application/json; charset=utf-8';
  protected $categorie = array();
  protected $status = -1;
  protected $timefilter = -1;
  protected $token = "";
  protected $token_filters = array('distance' => 0, 'categorie' => 0, 'address' => 0);
  protected $token_filter_enabled = False;
  protected $scope = "";
  protected $count = -1;
  protected $offset = -1;
  protected $approved = -1;
  protected $cityid = -1;
  protected $authkey = -1;
  protected $returnempty = False;

  function __construct()
  {
    global $db;
    global $acls;
    $this->db = $db;
    $this->acls = $acls;
  }
 
  public function showNonApproved() : bool
  {
    $query = mysqli_query($this->db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_shownonapproved' LIMIT 1");
    $result = mysqli_fetch_array($query);
    if (!isset($result['config_value']) OR $result['config_value'] == 0) {
      return False;
    }
    else {
      return True;
    }
  }

  public function setFormat($value) : void
  {
    if (!array_key_exists($value, $this->format_list)) {
      throw new Exception("${value} format not available");
    }

    $this->format = $value;
    $this->formatheader = $this->format_list[$value];
  }

  public function setCategorie($value) : void
  {
    $this->categorie = explode(',',$value);
  }

  public function setStatus($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->status = intval($value);
  }

  public function setTimefilter($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->timefilter = intval($value);
  }

  public function setToken($value) : void
  {
    $this->token = $value;
  }

  public function setTokenFilers($filters,$fdistance=-1) {
    $filters_list = explode(',',$filters);
    foreach($filters_list as $filtername) {
      $this->token_filters[$filtername] = 1;
      $this->token_filter_enabled = True;
    }
    if(is_numeric($fdistance)) {
      $this->token_filter_distance = intval($fdistance);
    }
  }

  public function setScope($value) : void
  {
    $this->scope = mysqli_real_escape_string($this->db, $value);
  }

  public function setCount($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->count = intval($value);
  }

  public function setOffset($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->offset = intval($value);
  }

  public function setApproved($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }
  
    if (isset($this->authkey) && (getrole($this->authkey, $this->acls) == "admin" OR getrole($this->authkey, $this->acls) == "moderator")) {
      $this->approved = intval($value);
    }  
    elseif (intval($value) == 0 && $this->showNonApproved()) {
      $this->approved = 0;
    }
    elseif (intval($value) == 1) {
      $this->approved = 1;
    } 
    else {
      $this->returnempty = True; 
    }
   
  }

  public function setCityid($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->cityid = intval($value);
  }

  public function setAuthKey($value) : void
  {
    $this->authkey = mysqli_real_escape_string($this->db, $value);
  }

  public function getLimitQuery($count, $offset) : string
  {
    $limit = "";
    if ($count > -1) {
      $limit = 'LIMIT ' . $count;
      if ($offset > -1) {
        $final_offset = intval($offset);
        $limit .= ' OFFSET ' . $final_offset;
      }
    }

    return $limit;
  }

  public function getQuery() : string
  {
    $where = "";

    if (count($this->categorie) > 0) {
      $categorie_search = " AND obs_categorie IN (";
      foreach($this->categorie as $categorie_item) {
        $categorie_search .= "'" . $categorie_item . "',";
      }
      $categorie_search = rtrim($categorie_search,',');
      $where .= $categorie_search . ')';
    }

    if ($this->timefilter > -1) {
      $where .= ' AND obs_time > ' . $this->timefilter;
    }

    if ($this->token != "" && !$this->token_filter_enabled) {
      $where .= " AND obs_token = '" . $this->token . "'";
    }

    if ($this->scope != "") {
      if ($this->scope != '34_montpellier') {
        $where .= " AND obs_scope = '" . $this->scope . "'";
      }
    }

    if ($this->approved != -1) {
     	$where .= " AND obs_approved = '" . $this->approved . "'";
    }
    else {
      if ($this->showNonApproved() || (isset($this->authkey) && (getrole($this->authkey, $this->acls) == "admin" OR getrole($this->authkey, $this->acls) == "moderator"))) {
        $where .= " AND (obs_approved=0 OR obs_approved=1)";
      }
      else {
        $where .= " AND obs_approved=1";
      }
    }

    if ($this->cityid != 0 && $this->cityid != -1) {
      $where .= " AND obs_city = '" . $this->cityid . "'";
    }
    $limit = $this->getLimitQuery($this->count, $this->offset);

    $query = "SELECT obs_id,
    obs_token,
    obs_city,
    obs_cityname,
    obs_coordinates_lat,
    obs_coordinates_lon,
    obs_address_string,
    obs_comment,
    obs_explanation,
    obs_time,
    obs_status,
    obs_categorie,
    obs_approved
FROM obs_list
WHERE obs_complete=1
" . $where . "
ORDER BY obs_time DESC
" . $limit;
    
    return $query;
  }


  public function getIssues() : array
  {
    $json = array();

    if(!$this->returnempty) {
      if($this->token_filter_enabled) {
        $token_query = mysqli_query($this->db,"SELECT * FROM obs_list WHERE obs_token='".$this->token."' LIMIT 1");
        $token_result = mysqli_fetch_array($token_query);
      }

      $rquery = mysqli_query($this->db, $this->getQuery());
      if (mysqli_num_rows($rquery) > 0) {
        while ($result = mysqli_fetch_array($rquery)) {
          $token = $result['obs_token'];
          $obs_status = getObsStatus($this->db,$result['obs_id']);
	  if ($this->status > -1 && $this->status != $obs_status) {
	    continue;
          }		  
          $issue = array(
            "token" => $result['obs_token'],
            "coordinates_lat" => $result['obs_coordinates_lat'],
            "coordinates_lon" => $result['obs_coordinates_lon'],
            "address" => $result['obs_address_string'],
            "comment" => $result['obs_comment'],
            "explanation" => $result['obs_explanation'],
            "time" => $result['obs_time'],
            "status" => $obs_status,
            "group" => 0,
            "categorie" => $result['obs_categorie'],
            "approved" => $result['obs_approved']
          );

          if (!empty($result['obs_city']) && $result['obs_city'] != 0) {
            $cityquery = mysqli_query($this->db,"SELECT city_name FROM obs_cities WHERE city_id='".$result['obs_city']."' LIMIT 1");
            $cityresult = mysqli_fetch_array($cityquery);
            $issue['cityname'] = $cityresult['city_name'];
            $issue['address'] = preg_replace('/^([^,]*),(?:[^,]*)$/','\1',$issue['address']);
            if (!isset($_GET['cityfield']) || $_GET['cityfield'] != "1") {
              $issue['address'] = $issue['address'] . ', '. $issue['cityname'];
            }
          }
          elseif (!empty($result['obs_cityname'])) {
        	  $issue['cityname'] = $result['obs_cityname'];
        	  $issue['address'] = preg_replace('/^([^,]*),(?:[^,]*)$/','\1',$issue['address']);
        	  if (!isset($_GET['cityfield']) || $_GET['cityfield'] != "1") {
              $issue['address'] = $issue['address'] . ', '. $issue['cityname'];
            }
          }
          elseif (preg_match('/^([^,]*),([^,]*)$/',$issue['address'],$cityInadress)) {
            if(count($cityInadress) == 3) {
              $issue['cityname'] = trim($cityInadress[2]);
        	    $issue['address'] = trim($cityInadress[1]);
        	    if (!isset($_GET['cityfield']) || $_GET['cityfield'] != "1") {
                $issue['address'] = $issue['address'] . ', '. $issue['cityname'];
        	    }
            }
          }

          if (isset($_GET['lat']) && isset($_GET['lon']) && is_numeric($_GET['radius'])) {
            $lat = mysqli_real_escape_string($this->db, $_GET['lat']);
            $lon = mysqli_real_escape_string($this->db, $_GET['lon']);
            $radius = intval($_GET['radius']);
            if (distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm') <= $radius) {
              $issue['distance'] = distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm');
              $json[] = $issue;
	          }
          } 
          elseif($this->token_filter_enabled) {
        	  if($token_result['obs_categorie'] == $result['obs_categorie'] OR !$this->token_filters['categorie']) {
    	        if(((flatstring($token_result['obs_address_string']) == flatstring($result['obs_address_string'])) AND $this->token_filters['address']) OR
              (distance($token_result['obs_coordinates_lat'], $token_result['obs_coordinates_lon'], $result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $unit = 'm') < $this->token_filter_distance AND $this->token_filters['distance'])) {
                $json[] = $issue;
	            }
	          }
        	}
        	else {
            $json[] = $issue;
          }
        }
      }
    }
    return $json;
  }

  public function outputToWebServer() : void
  {
    $json = $this->getIssues();

    header('Content-Type: ' . $this->formatheader);
    switch ($this->format) {
      case 'json':
        echo json_encode($json, JSON_PRETTY_PRINT);
        break;

      case 'csv':
        $sep = ',';
        $firstline = '';

        if (count($json) > 0) {
          foreach (array_keys($json[0]) as $column) {
            $firstline .= $column . $sep;
          }
          echo rtrim($firstline, ',');
          echo "\n";

          foreach ($json as $data) {
            $line = '';
            foreach ($data as $value) {
              $line .= str_replace(',', '_', $value) . $sep;
            }
            echo rtrim($line, ',') . "\n";
          }
        }
        break;
      case 'geojson':
        $features = array();
        foreach ($json as $key => $value) {
          $features[] = array(
            'type' => 'Feature',
            'geometry' => array(
              'type' => 'Point',
              'coordinates' => array(
                $value['coordinates_lon']+0,
                $value['coordinates_lat']+0
              ),
            ),
            'properties' => array(
              'name' => $value['token'].' '.$value['comment'],
              'description' => '{{'.$config['HTTP_PROTOCOL'].'://'.$config['URLBASE'].'/images/'.$value['token'].'.jpg}} '.$value['explanation'],
              'token' => $value['token'],
              'address' => $value['address'],
              'comment' => $value['comment'],
              'explanation' => $value['explanation'],
              'time' => $value['time'],
              'group' => $value['group'],
              'categorie' => $value['categorie'],
              'approved' => $value['approved'],
            ),
          );
        }
        $new_data = array(
          'type' => "FeatureCollection",
          'features' => $features,
        );
        echo json_encode($new_data, JSON_PRETTY_PRINT);
        break;
    }
  }
}


if (!debug_backtrace()) {
  $export = new GetIssues();

  if (isset($_GET['key'])) {
    $export->setAuthKey($_GET['key']);
  }

  if (isset($_GET['format'])) {
    $export->setFormat($_GET['format']);
  }

  if (isset($_GET['c'])) {
    $export->setCategorie($_GET['c']);
  }

  if (isset($_GET['t'])) {
    $export->setTimefilter($_GET['t']);
  }

  if (isset($_GET['status'])) {
    $export->setStatus($_GET['status']);
  }

  if (isset($_GET['token'])) {
    $export->setToken($_GET['token']);
  }

  if (isset($_GET['token']) && isset($_GET['tokenfilters'])) {
    $export->setTokenFilers($_GET['tokenfilters']);
  }

  if (isset($_GET['token']) && isset($_GET['tokenfilters']) && isset($_GET['fdistance'])) {
    $export->setTokenFilers($_GET['tokenfilters'],$_GET['fdistance']);
  }

  if (isset($_GET['scope'])) {
    $export->setScope($_GET['scope']);
  }

  if (isset($_GET['count'])) {
    $export->setCount($_GET['count']);
  }

  if (isset($_GET['approved'])) {
    $export->setApproved($_GET['approved']);
  }

  if (isset($_GET['cityid'])) {
    $export->setCityid($_GET['cityid']);
  }

  # Export datas
  $export->outputToWebServer();
}
