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
 * Optional blur server: when one is configured, every uploaded photo (observations
 * and resolutions) is sent to it, and replaced by the photo it returns, with the
 * faces and licence plates masked (if it fails, the photo is kept as sent and
 * left to the moderators). See blur-server/ (docker-compose profile "blur");
 * any server answering the same call works (e.g. SGBlur):
 *
 *   POST <url>, multipart field "picture" -> 200 and the blurred image
 *
 * The server is the "vigilo_blur_url" setting of the admin, or else the
 * VIGILO_BLUR_URL environment variable. None: photos are published as sent.
 */

function blur_server_url($db)
{
    $query = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_blur_url' LIMIT 1");
    $row   = $query ? mysqli_fetch_array($query) : null;
    if ($row && trim((string) $row['config_value']) !== '') {
        return trim($row['config_value']);
    }
    $env = getenv('VIGILO_BLUR_URL');
    return ($env !== false) ? trim($env) : '';
}

/*
 * Sends the JPEG $filepath to the blur server $url and replaces it with the blurred
 * photo. Returns null when done, else the reason of the failure (the file is then
 * left as it was).
 */
function blur_photo($url, $filepath)
{
    $timeout = intval(getenv('VIGILO_BLUR_TIMEOUT'));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('picture' => curl_file_create($filepath, 'image/jpeg', 'photo.jpg')));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 60);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return 'blur server unreachable: ' . $error;
    }
    if ($code != 200) {
        return 'blur server answered HTTP ' . $code;
    }
    $image = @imagecreatefromstring($response);
    if ($image === false) {
        return 'blur server did not return an image';
    }
    // The photos are stored as JPEG: convert what an other server may return
    if (substr($response, 0, 3) !== "\xFF\xD8\xFF") {
        ob_start();
        imagejpeg($image, null, 90);
        $response = ob_get_clean();
    }
    if (file_put_contents($filepath, $response) === false) {
        return 'blurred photo could not be written';
    }
    return null;
}
