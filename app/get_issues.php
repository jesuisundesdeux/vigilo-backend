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

header('BACKEND_VERSION: ' . BACKEND_VERSION);

class GetIssues
{

  private $format_list = array(
    'csv' => 'text/csv',
    'json' => 'application/json',
    'geojson' => 'application/json'
  );

  protected $format = 'json';
  protected $formatheader = 'json';
  protected $categorie = -1;
  protected $status = -1;
  protected $timefilter = -1;
  protected $token = "";
  protected $scope = "";
  protected $count = -1;
  protected $offset = -1;

  function __construct()
  {
    global $db;
    $this->db = $db;
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
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->categorie = intval($value);
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

  public function getQuery() : string
  {
    $where = "";

    if ($this->categorie > -1) {
      $where .= ' AND obs_categorie = ' . $this->categorie;
    }

    if ($this->timefilter > -1) {
      $where .= ' AND obs_time > ' . $this->timefilter;
    }

    if ($this->status > -1) {
      $where .= 'AND obs_status = ' . $this->status;
    }

    if ($this->token != "") {
      $where .= " AND obs_token = '" . $this->token . "'";
    }

    if ($this->scope != "") {
      if ($this->scope != '34_montpellier') {
        $where .= " AND obs_scope = '" . $this->scope . "'";
      }
    }

    $limit = "";
    if ($this->count > -1) {
      $limit = 'LIMIT ' . $this->count;
      if ($this->offset > -1) {
        $offset = intval($_GET['offset']);
        $limit .= ' OFFSET ' . $offset;
      }
      $where .= 'AND obs_status = ' . $this->status;
    }

    $query = mysqli_query($this->db, "SELECT obs_token,
    obs_coordinates_lat,
    obs_coordinates_lon,
    obs_address_string,
    obs_comment,
    obs_explanation,
    obs_time,
    obs_categorie,
    obs_approved 
FROM obs_list
WHERE obs_complete=1 
AND (obs_approved=0 OR obs_approved=1)
" . $where . " 
ORDER BY obs_time DESC 
" . $limit);
  }


  public function getIssues() : array
  {
    $json = array();

    $rquery = mysqli_query($this->db, $this->getQuery());
    if (mysqli_num_rows($rquery) > 0) {
      while ($result = mysqli_fetch_array($rquery)) {
        $token = $result['obs_token'];
        $issue = array(
          "token" => $result['obs_token'],
          "coordinates_lat" => $result['obs_coordinates_lat'],
          "coordinates_lon" => $result['obs_coordinates_lon'],
          "address" => $result['obs_address_string'],
          "comment" => $result['obs_comment'],
          "explanation" => $result['obs_explanation'],
          "time" => $result['obs_time'],
          "group" => 0,
          "categorie" => $result['obs_categorie'],
          "approved" => $result['obs_approved']
        );

        if (isset($_GET['lat']) && isset($_GET['lon']) && is_numeric($_GET['radius'])) {
          $lat = mysqli_real_escape_string($db, $_GET['lat']);
          $lon = mysqli_real_escape_string($db, $_GET['lon']);
          $radius = intval($_GET['radius']);
          if (distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm') <= $radius) {
            $issue['distance'] = distance($result['obs_coordinates_lat'], $result['obs_coordinates_lon'], $lat, $lon, $unit = 'm');
            $json[] = $issue;
          }
        } else {
          $json[] = $issue;
        }
      }
    }

    return $json;
  }

  public function outputToWebServer() : void
  {
    $json = $export->getIssues();

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
                $value['coordinates_lat'],
                $value['coordinates_lon']
              ),
            ),
            'properties' => array(
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

  if (isset($_GET['scope'])) {
    $export->setScope($_GET['scope']);
  }

  if (isset($_GET['c'])) {
    $export->setCategorie($_GET['c']);
  }

  # Export categories
  $export->exportToWebServer($json);
}

