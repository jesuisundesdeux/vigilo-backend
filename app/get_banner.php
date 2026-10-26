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

/*
 * Banner (announcement) configured in the admin: returns {"banner": {...}} when a message is set
 * and the current time is within its window, {"banner": null} otherwise. The web app shows it as a
 * bar or a modal (its "display"), keeps it dismissed by its "id" until the content changes.
 */
$cwd = dirname(__FILE__);

require_once("{$cwd}/includes/common.php");
require_once("{$cwd}/includes/functions.php");

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Scope (obs_scopes name) of the instance the web app runs against: a banner may target some scopes
// only. Without a known scope, only banners that target every scope are returned.
$scope_id = 0;
if (isset($_GET['scope']) && $_GET['scope'] !== '') {
    $scope  = mysqli_real_escape_string($db, $_GET['scope']);
    $squery = mysqli_query($db, "SELECT scope_id FROM obs_scopes WHERE scope_name = '$scope' LIMIT 1");
    $srow   = $squery ? mysqli_fetch_assoc($squery) : null;
    if ($srow) {
        $scope_id = intval($srow['scope_id']);
    }
}

echo json_encode(array('banner' => vigilo_banner($db, $scope_id)), JSON_PRETTY_PRINT);
