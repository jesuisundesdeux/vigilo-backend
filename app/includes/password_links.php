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
 * Links to set the password of an account (page Comptes, admin/password.php): a random
 * token of 256 bits, sent by the administrator to the person; only its SHA-256 is stored.
 * One-time, valid VIGILO_PASSWORD_LINK_HOURS hours; a new link of an account invalidates
 * the previous ones. For the accounts that sign in to the panel (admin, citystaff).
 */

define('VIGILO_PASSWORD_LINK_HOURS', 72);
define('VIGILO_PASSWORD_MIN_LENGTH', 10);

/* Creates a link for an account (the previous ones are removed): returns the token */
function password_link_create($db, $role_id, $login)
{
    mysqli_query($db, "DELETE FROM obs_password_links WHERE link_roleid = " . intval($role_id) . " OR link_expires < " . (time() - 30 * 86400));
    $token = bin2hex(random_bytes(32));
    $ok    = mysqli_query($db, "INSERT INTO obs_password_links (link_roleid, link_hash, link_created, link_created_login, link_expires)
                                VALUES (" . intval($role_id) . ", '" . hash('sha256', $token) . "', " . time() . ",
                                        '" . mysqli_real_escape_string($db, (string) $login) . "', " . (time() + VIGILO_PASSWORD_LINK_HOURS * 3600) . ")");
    return $ok ? $token : null;
}

/* Valid link of a token (not used, not expired, account with a login): row with role_login, role_name; null otherwise */
function password_link_find($db, $token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $query = mysqli_query($db, "SELECT obs_password_links.*, obs_roles.role_login, obs_roles.role_name FROM obs_password_links
                                INNER JOIN obs_roles ON obs_roles.role_id = obs_password_links.link_roleid
                                WHERE obs_password_links.link_hash = '" . hash('sha256', $token) . "' AND obs_password_links.link_used = 0
                                  AND obs_password_links.link_expires >= " . time() . " AND obs_roles.role_login <> '' LIMIT 1");
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    return $row ? $row : null;
}

/* Sets the password of the account of a valid link and uses it (once): true if done */
function password_link_use($db, $link, $password)
{
    mysqli_query($db, "UPDATE obs_password_links SET link_used = " . time() . " WHERE link_id = " . intval($link['link_id']) . " AND link_used = 0");
    if (mysqli_affected_rows($db) != 1) {
        return false;
    }
    mysqli_query($db, "DELETE FROM obs_password_links WHERE link_roleid = " . intval($link['link_roleid']) . " AND link_id <> " . intval($link['link_id']));
    return (bool) mysqli_query($db, "UPDATE obs_roles SET role_password = '" . mysqli_real_escape_string($db, password_hash($password, PASSWORD_DEFAULT)) . "'
                                     WHERE role_id = " . intval($link['link_roleid']));
}

/* Address of the page of a link: URL of the instance (configuration), else of the request */
function password_link_url($token)
{
    global $config;
    $base = isset($config['URLBASE']) ? trim((string) $config['URLBASE'], " /") : '';
    if ($base !== '') {
        $proto = (isset($config['HTTP_PROTOCOL']) && $config['HTTP_PROTOCOL'] !== '') ? $config['HTTP_PROTOCOL'] : 'https';
        $url   = $proto . '://' . $base . '/admin/password.php';
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host  = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
        $dir   = isset($_SERVER['SCRIPT_NAME']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '/admin';
        $url   = ($https ? 'https' : 'http') . '://' . $host . $dir . '/password.php';
    }
    return $url . '?token=' . $token;
}
