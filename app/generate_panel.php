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


$query = mysqli_query($db, "SELECT * FROM obs_config
                            WHERE config_param='vigilo_panel'
                            LIMIT 1");
$result = mysqli_fetch_array($query);
$panel_path = $result['config_value'];

if(file_exists('panels/'.$panel_path.'/panel.php')) {
  require_once('panels/'.$panel_path.'/panel.php');
} else {
  die('Panel not exists');
}

