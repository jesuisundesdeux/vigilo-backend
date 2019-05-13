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

require_once(dirname(__FILE__) . "/../config/config.php");

global $config;

define('BACKEND_VERSION','0.0.8');

date_default_timezone_set($config['VIGILO_TIMEZONE']);

$db = mysqli_connect($config['MYSQL_HOST'],
                     $config['MYSQL_USER'],
                     $config['MYSQL_PASSWORD'],
                     $config['MYSQL_DATABASE']);

mysqli_set_charset($db, $config['MYSQL_CHARSET']);

# ACL
$acls = array();
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while ($roles_result = mysqli_fetch_array($roles_query)) {
  $role = $roles_result['role_name'];
  $acls[$role][] = $roles_result['role_key'];
}

