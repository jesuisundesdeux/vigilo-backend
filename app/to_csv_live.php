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

class ToCSV
{
  protected $db = -1;
  protected $separator = ',';
  protected $categorie = -1;
  protected $timefilter = -1;

  function __construct()
  {
    global $db;
    $this->db = $db;
  }

  public function setCategorie($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->categorie = intval($value);
  }

  public function setTime($value) : void
  {
    if (!is_numeric($value)) {
      throw new Exception("${value} is not numeric value");
    }

    $this->timefilter = intval($value);

  }

  public function getQuery() : string
  {
    # Filter observations by categories
    $filtered = false;
    $where = "";
    if ($this->timefilter > -1 or $this->categorie > -1) {
      $where = " Where";
    }

    if ($this->categorie > -1) {
      if ($filtered) {
        $where .= " And";
      }
      $where .= " obs_categorie=" . $this->categorie;
      $filtered = true;
    }

    if ($this->timefilter > -1) {
      if ($filtered) {
        $where .= " And";
      }
      $where .= " obs_time>=" . $this->timefilter;
      $filtered = true;
    }

    $query = "SELECT * FROM obs_list" . $where;
    return $query;

  }

  public function getDatas() : array
  {
    $lines = array();
    array_push($lines,'lat' . $this->separator . 'long' . $this->separator . 'rue' . $this->separator . 'comment' . $this->separator . 'categorie' . $this->separator . 'token' . $this->separator . "time\n");
    $rquery = mysqli_query($this->db, $this->getQuery());

    if (mysqli_num_rows($rquery) == 0) {
      return $lines;
    }

    while ($result = mysqli_fetch_array($rquery)) {
      $coordinates_lat = $result['obs_coordinates_lat'];
      $coordinates_lon = $result['obs_coordinates_lon'];
      $street_name = $result['obs_address_string'];
      $comment = $result['obs_comment'];
      $categorie = $result['obs_categorie'];
      $token = $result['obs_token'];
      $time = $result['obs_time'];
      $status = $result['obs_status'];
      $version = $result['obs_app_version'];

      $line = $coordinates_lat . '~' . $coordinates_lon . '~' . $street_name . '~' . $comment . '~' . $categorie . '~' . $token . '~' . $time . "\n";
      $line = str_replace(',', '_', $line);
      $line = str_replace('~', $this->separator, $line);
      array_push($lines,$line);
    }

    return $lines;
  }
}

if (!debug_backtrace()) {
  $tocsv = new ToCSV();

  if (isset($_GET["c"]) and is_numeric($_GET["c"])) {
    $tocsv->scategorie = intval($_GET["c"]);
  }

  if (isset($_GET["t"]) and is_numeric($_GET["t"])) {
    $tocsv->timefilter = $_GET["t"];
  }

  echo "============";

  echo $tocsv->getQuery();
  echo $tocsv->getDatas();
}

