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

echo json_encode(array('banner' => vigilo_banner($db)), JSON_PRETTY_PRINT);
