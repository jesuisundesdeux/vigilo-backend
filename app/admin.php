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

header('Cache-Control: no-cache, must-revalidate, max-age=0');

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");
require_once("${cwd}/includes/functions.php");

$realm = 'Acces restreint';

if (!isset($_SERVER['PHP_AUTH_USER'])) {
  header('HTTP/1.1 401 Authorization Required');
  header('WWW-Authenticate: Basic realm="Access denied"');
  echo 'Acces interdit';
  exit;
}

$http_login = $_SERVER['PHP_AUTH_USER'];
$http_password = $_SERVER['PHP_AUTH_PW'];

$query_roles = mysqli_query($db, "SELECT role_key,role_login,role_password FROM obs_roles WHERE role_login='".$http_login."' LIMIT 1");
if(mysqli_num_rows($query_roles) != 1) {
  header('HTTP/1.1 401 Authorization Required');
  header('WWW-Authenticate: Basic realm="Access denied"');
  echo 'Acces interdit';
  exit;

}
$result_role = mysqli_fetch_array($query_roles);

if(hash('sha256',$http_password) != $result_role['role_password']) {
  header('HTTP/1.1 401 Authorization Required');
  header('WWW-Authenticate: Basic realm="Access denied"');
  echo 'Acces interdit';
  exit;
}

$role_key = $result_role['role_key'];

if(isset($_GET['action']) || !empty($_GET['action'])) {
  if($_GET['action'] == "photo") {
    $token = mysqli_real_escape_string($db, $_GET['token']);
    $secretid = mysqli_real_escape_string($db, $_GET['secretid']);
    
    # Get issue information
    $query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");
    
    if (mysqli_num_rows($query) == 1) {
        $result = mysqli_fetch_array($query);
        $approved = $result['obs_approved'];
      
      
      ## Init other images components :
      echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
      echo '<table cellpadding="0" cellspacing="0" class="db-table">';
      echo '<tr><th>photo</th><th>action</th></tr>';
    
      echo '<tr>';
      echo '<td><img src="/generate_panel.php?token='.$token.'&secretid='.$secretid.'"></img></td>';
      echo '<td>';
      echo '<a href="/approve.php?token='.$token.'&approved=1&key='.$role_key.'">Approuver photo</a><br />';
      echo '<a href="/approve.php?token='.$token.'&approved=2&key='.$role_key.'">Desapprouver photo</a><br />';
      echo 'STATUS:'.$approved;
    
      echo '</td>';
      echo '</tr>';
      echo '</table>';
    }
  }
}
else {
  $sresample=200;

  # Get issue information
  $query = mysqli_query($db, "SELECT * FROM obs_list ORDER BY obs_time DESC");

  if (mysqli_num_rows($query) > 0) {
    echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
    echo '<table cellpadding="0" cellspacing="0" class="db-table">';
    echo '<tr><th>time</th><th>street_name</th><th>comment</th><th>categorie<th>photo</th><th>approved</th><th>delete</th></tr>';
    while ($result = mysqli_fetch_array($query)) {
      $coordinates_lat = $result['obs_coordinates_lat'];
      $coordinates_lon = $result['obs_coordinates_lon'];
      $street_name = $result['obs_address_string'];
      $comment = $result['obs_comment'];
      $categorie = $result['obs_categorie'];
      $token = $result['obs_token'];
      $time = $result['obs_time'];
      $status = $result['obs_status'];
      $version = $result['obs_app_version'];
      $approved = $result['obs_approved'];
      $secretid = $result['obs_secretid'];
  
      echo '<tr>';
      echo '<td>'.$time.'</td>';
      echo '<td>'.$street_name.'</td>';
      echo '<td>'.$comment.'</td>';
      echo '<td>'.$categorie.'</td>';
      echo '<td><a href="/admin.php?action=photo&token='.$token.'&secretid='.$secretid.'" target="_blank"><img src="/generate_panel.php?s='.$sresample.'&token='.$token.'"</img><a/></td>';
      echo '<td>'.$approved.'</td>';
      echo '<td><a href="/delete.php?token='.$token.'&secretid='.$secretid.'">Supprimer</a></td>';
      echo "</tr>\n";
    }
    echo '</table><br />';
  }
}

