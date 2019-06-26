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

if(file_exists(dirname(__FILE__) . "/../config/config.php")) {
  require_once(dirname(__FILE__) . "/../config/config.php");
}
else {
  echo "Fichier config/config.php manquant";
  error_log("[FATAL] Fichier config/config.php manquant");
  exit();
}

global $config;

define('BACKEND_VERSION','0.0.10');
error_log(var_dump($config));
$db = mysqli_connect($config['MYSQL_HOST'],
                     $config['MYSQL_USER'],
                     $config['MYSQL_PASSWORD'],
                     $config['MYSQL_DATABASE']);

$config_query = mysqli_query($db,"SELECT * FROM obs_config");
while($config_result = mysqli_fetch_array($config_query)) {
  switch($config_result['config_param']) {
    case "vigilo_urlbase":
      $config['URLBASE'] = $config_result['config_value'];
    break;
    case "vigilo_http_proto":
      $config['HTTP_PROTOCOL'] =  $config_result['config_value'];
    break;
    case 'vigilo_name':
      $config['VIGILO_NAME'] = $config_result['config_value'];
    break;
    case 'vigilo_language':
      $config['VIGILO_LANGUAGE'] = $config_result['config_value'];
    break;
    case 'vigilo_mapquest_api':
      $config['MAPQUEST_API'] = $config_result['config_value'];      
    break;
    case 'twitter_expiry_time':
      $config['APPROVE_TWITTER_EXPTIME'] = $config_result['config_value'];
    break;
    case 'mysql_charset':
      $config['MYSQL_CHARSET'] = $config_result['config_value'];
    break;
    case 'vigilo_timezone':
      $config['VIGILO_TIMEZONE'] = $config_result['config_value'];
    break;
  }
}

date_default_timezone_set($config['VIGILO_TIMEZONE']);
mysqli_set_charset($db, $config['MYSQL_CHARSET']);

# ACL
$acls = array();
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while ($roles_result = mysqli_fetch_array($roles_query)) {
  $role = $roles_result['role_name'];
  $acls[$role][] = $roles_result['role_key'];
}

# Observation status
$status_list = array(
	           0 => array(
			     "name" => "Nouvelle observation",
			     "roles" => array("admin"),
			     "nextstatus" => array(1,2,3,4)),
                   1 => array(
                             "name" => "Observation résolue",
                             "roles" => array("admin"),
                             "nextstatus" => array()),
		   2 => array(
			     "name" => "Observation prise en compte",
			     "roles" => array("admin","citystaff"),
			     "nextstatus" => array(1,3,4,5)),
		   3 => array(
			     "name" => "Observation en cours de résolution",
			     "roles" => array("admin","citystaff"),
			     "nextstatus" => array(1,4,5)),
		   4 => array(
			     "name" => "Observation indiquée comme résolue",
			     "roles" => array("all"),
			     "nextstatus" => array(1,5)),
		   5 => array(
			     "name" => "Résolution refusée",
			     "roles" => array("admin"),
			     "nextstatus" => array(1,3,4)));

