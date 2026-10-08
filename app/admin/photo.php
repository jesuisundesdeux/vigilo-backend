<?php
/*
Copyright (C) 2026 Velocité Montpellier

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
 * Photo of an observation (or of a resolution with type=resolution) for the admin:
 * never pixelated, so that moderators see what they approve. Requires an admin session;
 * a citystaff only sees the observations of their cities.
 */
require_once('../includes/security.php');

vigilo_session_start();

if (!isset($_SESSION['login']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], array('admin', 'citystaff'), true)) {
    http_response_code(403);
    exit;
}

require_once('../includes/common.php');
require_once('../includes/functions.php');
require_once('../includes/images.php');
require_once('../includes/handle.php');

$token = isset($_GET['token']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $_GET['token']) : '';
$type  = (isset($_GET['type']) && $_GET['type'] == 'resolution') ? 'resolution' : 'obs';
$width = (isset($_GET['s']) && is_numeric($_GET['s'])) ? max(50, min(1024, intval($_GET['s']))) : 1024;

if ($type == 'obs') {
    $query = mysqli_query($db, "SELECT obs_city FROM obs_list WHERE obs_token='" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
    $obs   = $query ? mysqli_fetch_array($query) : null;
    if (!$obs || ($_SESSION['role'] == 'citystaff' && !in_array(intval($obs['obs_city']), getRoleCityIds($db, $_SESSION['login']), true))) {
        http_response_code(404);
        exit;
    }
    $path = dirname(__FILE__) . '/../' . $config['DATA_PATH'] . 'images/' . $token . '.jpg';
} else {
    if (!isResolutionTokenExists(mysqli_real_escape_string($db, $token))) {
        http_response_code(404);
        exit;
    }
    $path = dirname(__FILE__) . '/../' . $config['DATA_PATH'] . 'images/resolutions/' . $token . '.jpg';
}

if (!file_exists($path)) {
    $path = dirname(__FILE__) . '/../style/image_404.jpg';
}

$image = @imagecreatefromjpeg($path);
if (!$image) {
    http_response_code(500);
    exit;
}
if (imagesx($image) > $width) {
    $resized = resizeImage($image, $width, 1024);
    if ($resized !== false) {
        $image = $resized;
    }
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
imagejpeg($image, null, 85);
