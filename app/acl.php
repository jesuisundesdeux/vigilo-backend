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

$status = 0;
$role = Null;

if(isset($_GET['key'])) {
  $key=$_GET['key'];
  $role = getrole($key, $acls);
} else{
  error_log('Private key not provided');
  $status = 1;
}


if($status != 0) {
  http_response_code(500);
}

echo json_encode(array('role'=>$role,'status'=>$status));

