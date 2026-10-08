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
 * Admin security helpers: session, CSRF tokens, login throttling, audit log,
 * HTML escaping and rate limiting of the public API.
 */

/* HTML escaping for templates */
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function vigilo_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

/* Admin session with hardened cookie */
function vigilo_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $params = array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => vigilo_is_https(),
        'httponly' => true,
        'samesite' => 'Lax'
    );
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $params['secure'], true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('VIGILOADMIN');
    session_start();

    // Idle sessions expire after 8 hours
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 8 * 3600) {
        $_SESSION = array();
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
}

function vigilo_admin_headers()
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

/* CSRF */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '" />';
}

/* To append to the URL of an action link: "&csrf_token=..." */
function csrf_query()
{
    return '&csrf_token=' . urlencode(csrf_token());
}

function csrf_valid()
{
    $sent = '';
    if (isset($_POST['csrf_token'])) {
        $sent = (string) $_POST['csrf_token'];
    } elseif (isset($_GET['csrf_token'])) {
        $sent = (string) $_GET['csrf_token'];
    }
    return !empty($_SESSION['csrf_token']) && $sent !== '' && hash_equals($_SESSION['csrf_token'], $sent);
}

/*
 * Every admin request that changes something (a POST, or a GET with an "action")
 * must carry the session CSRF token. Otherwise the request is neutralized: the
 * action and the posted fields are dropped and the page is displayed as is.
 */
function csrf_protect_request()
{
    $changes = $_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action']);
    if (!$changes || csrf_valid()) {
        return true;
    }
    $_POST = array();
    unset($_GET['action']);
    return false;
}

/* Login throttling: 5 failures per login and IP in 15 minutes */
define('VIGILO_LOGIN_MAX_FAILURES', 5);
define('VIGILO_LOGIN_WINDOW', 900);

function vigilo_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function login_is_throttled($db, $login)
{
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_login_attempts
                                WHERE attempt_time > " . (time() - VIGILO_LOGIN_WINDOW) . "
                                AND (attempt_login = '" . mysqli_real_escape_string($db, $login) . "'
                                     OR attempt_ip = '" . mysqli_real_escape_string($db, vigilo_client_ip()) . "')");
    if (!$query) {
        return false;
    }
    return mysqli_fetch_array($query)[0] >= VIGILO_LOGIN_MAX_FAILURES * 2
        || login_failures($db, $login) >= VIGILO_LOGIN_MAX_FAILURES;
}

function login_failures($db, $login)
{
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_login_attempts
                                WHERE attempt_time > " . (time() - VIGILO_LOGIN_WINDOW) . "
                                AND attempt_login = '" . mysqli_real_escape_string($db, $login) . "'
                                AND attempt_ip = '" . mysqli_real_escape_string($db, vigilo_client_ip()) . "'");
    return $query ? mysqli_fetch_array($query)[0] : 0;
}

function login_record_failure($db, $login)
{
    mysqli_query($db, "INSERT INTO obs_login_attempts (attempt_login, attempt_ip, attempt_time)
                       VALUES ('" . mysqli_real_escape_string($db, $login) . "',
                               '" . mysqli_real_escape_string($db, vigilo_client_ip()) . "', " . time() . ")");
    mysqli_query($db, "DELETE FROM obs_login_attempts WHERE attempt_time < " . (time() - 86400));
}

function login_clear_failures($db, $login)
{
    mysqli_query($db, "DELETE FROM obs_login_attempts
                       WHERE attempt_login = '" . mysqli_real_escape_string($db, $login) . "'
                       AND attempt_ip = '" . mysqli_real_escape_string($db, vigilo_client_ip()) . "'");
}

/* Audit log of privileged actions (#75) */
function audit_log($action, $target = '', $details = '')
{
    global $db;
    $login = isset($_SESSION['login']) ? $_SESSION['login'] : '';
    $role  = isset($_SESSION['role']) ? $_SESSION['role'] : '';
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    }
    mysqli_query($db, "INSERT INTO obs_audit_log (audit_time, audit_login, audit_role, audit_ip, audit_action, audit_target, audit_details)
                       VALUES (" . time() . ",
                               '" . mysqli_real_escape_string($db, $login) . "',
                               '" . mysqli_real_escape_string($db, $role) . "',
                               '" . mysqli_real_escape_string($db, vigilo_client_ip()) . "',
                               '" . mysqli_real_escape_string($db, $action) . "',
                               '" . mysqli_real_escape_string($db, $target) . "',
                               '" . mysqli_real_escape_string($db, function_exists('vigilo_truncate') ? vigilo_truncate($details, 2000) : substr((string) $details, 0, 2000)) . "')");
}

/*
 * Rate limiting of the public API (#139): at most $max requests of a kind per IP in
 * $window seconds. Uses a table so that it works with several PHP workers.
 */
function api_rate_limited($db, $kind, $max, $window)
{
    $ip  = mysqli_real_escape_string($db, vigilo_client_ip());
    $now = time();
    if (mt_rand(1, 50) === 1) {
        mysqli_query($db, "DELETE FROM obs_rate_limit WHERE rl_time < " . ($now - 86400));
    }
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_rate_limit
                                WHERE rl_kind = '" . mysqli_real_escape_string($db, $kind) . "'
                                AND rl_ip = '" . $ip . "' AND rl_time > " . ($now - $window));
    if (!$query) {
        return false;
    }
    if (mysqli_fetch_array($query)[0] >= $max) {
        return true;
    }
    mysqli_query($db, "INSERT INTO obs_rate_limit (rl_kind, rl_ip, rl_time)
                       VALUES ('" . mysqli_real_escape_string($db, $kind) . "', '" . $ip . "', " . $now . ")");
    return false;
}
