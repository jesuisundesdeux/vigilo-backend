<?php
/*
Copyright (C) 2020 Velocité Montpellier

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
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$error_prefix = 'GET_CATEGORIES';

$query = mysqli_query($db, "SELECT * FROM obs_categories
                            ORDER BY categorie_order");

if (mysqli_num_rows($query) == 0) {
    jsonError($error_prefix, 'No categories returned', 'CATEGORIESEMPTY', 401);
    return;
}

$categories_list = getCategoriesList();
$categories = array();
while($result = mysqli_fetch_array($query)) {
  $categories[] = $categories_list[$result['categorie_id']];
}

echo json_encode($categories, JSON_PRETTY_PRINT);
