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
ini_set('display_errors', 'Off');

if (file_exists(dirname(__FILE__) . "/../config/config.php")) {
    // require, not require_once: the install check (includes/setup.php) may have read it already
    require(dirname(__FILE__) . "/../config/config.php");
} else {
    echo "Fichier config/config.php manquant";
    error_log("[FATAL] Fichier config/config.php manquant");
    exit();
}

global $config;

require_once(dirname(__FILE__) . '/version.php');

if (!isset($config['DATA_PATH'])) {
    $config['DATA_PATH'] = '';
}

// PHP >= 8.1 throws an exception on any SQL error: the code checks the results itself
mysqli_report(MYSQLI_REPORT_OFF);

if (!$db = mysqli_connect($config['MYSQL_HOST'], $config['MYSQL_USER'], $config['MYSQL_PASSWORD'], $config['MYSQL_DATABASE'])) {
    error_log("[FATAL] Connection à la base impossible");
    exit();
}

mysqli_query($db, "SET sql_mode = ''");

$config_query = mysqli_query($db, "SELECT * FROM obs_config");
while ($config_result = mysqli_fetch_array($config_query)) {
    switch ($config_result['config_param']) {
        case "vigilo_urlbase":
            $config['URLBASE'] = $config_result['config_value'];
            break;
        case "vigilo_http_proto":
            $config['HTTP_PROTOCOL'] = $config_result['config_value'];
            break;
        case 'vigilo_name':
            $config['VIGILO_NAME'] = $config_result['config_value'];
            break;
        case 'vigilo_language':
            $config['VIGILO_LANGUAGE'] = $config_result['config_value'];
            break;
        case 'mysql_charset':
            $config['MYSQL_CHARSET'] = $config_result['config_value'];
            break;
        case 'vigilo_timezone':
            $config['VIGILO_TIMEZONE'] = $config_result['config_value'];
            break;
    }
}

// Shared configuration (instances, national categories) and web app: vigilo-conf and
// app.vigilo.city, or those of a closed set of instances (demo): VIGILO_CONF_URL, VIGILO_WEBAPP_URL
define('VIGILO_CONF_DEFAULT_URL', 'https://raw.githubusercontent.com/jesuisundesdeux/vigilo-conf/main/main');
define('VIGILO_WEBAPP_DEFAULT_URL', 'https://app.vigilo.city/');
foreach (array('VIGILO_CONF_URL' => VIGILO_CONF_DEFAULT_URL, 'VIGILO_WEBAPP_URL' => VIGILO_WEBAPP_DEFAULT_URL) as $key => $default) {
    if (empty($config[$key])) {
        $config[$key] = getenv($key) ? (string) getenv($key) : $default;
    }
}
$config['VIGILO_CONF_URL']   = rtrim($config['VIGILO_CONF_URL'], '/');
$config['VIGILO_WEBAPP_URL'] = rtrim($config['VIGILO_WEBAPP_URL'], '/') . '/';
$config['CATEGORIES_NATIONAL_URL'] = $config['VIGILO_CONF_URL'] . "/categorielist.json";

date_default_timezone_set($config['VIGILO_TIMEZONE']);
mysqli_set_charset($db, $config['MYSQL_CHARSET']);

// Visit statistics (admin page "Fréquentation"): count one hit per public API route, aggregated by
// day (instance timezone). Admin pages, the CLI and the image-serving routes (get_photo,
// generate_panel, one call per asset) are excluded. Never breaks the response: a failure (e.g. the
// obs_api_stats table missing before the 0.0.40 migration) is ignored.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/admin/') === false) {
    $vigilo_api_route = basename($_SERVER['SCRIPT_NAME']);
    $vigilo_api_counted = array(
        'get_issues.php', 'get_scope.php', 'get_categories.php', 'get_banner.php', 'get_version.php',
        'create_issue.php', 'add_image.php', 'create_resolution.php', 'approve.php', 'delete.php', 'acl.php',
    );
    if (in_array($vigilo_api_route, $vigilo_api_counted, true)) {
        $vigilo_api_day = date('Y-m-d');
        $vigilo_api_r   = mysqli_real_escape_string($db, $vigilo_api_route);
        @mysqli_query($db, "INSERT INTO obs_api_stats (stat_date, stat_route, stat_count) VALUES ('$vigilo_api_day', '$vigilo_api_r', 1) ON DUPLICATE KEY UPDATE stat_count = stat_count + 1");
        // Unique visitors per day: a daily non-reversible fingerprint of the client IP (HMAC with the
        // instance secret, salted with the day). The IP itself is never stored, and a fingerprint cannot
        // be correlated from one day to the next. obs_api_visitors added by the 0.0.41 migration.
        $vigilo_api_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($vigilo_api_ip !== '') {
            $vigilo_secret_q = @mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param = 'vigilo_photo_link_secret' ORDER BY config_id LIMIT 1");
            $vigilo_secret_r = $vigilo_secret_q ? mysqli_fetch_row($vigilo_secret_q) : null;
            $vigilo_secret   = ($vigilo_secret_r && strlen((string) $vigilo_secret_r[0]) >= 16) ? (string) $vigilo_secret_r[0] : 'vigilo-visitors';
            $vigilo_visitor  = substr(hash_hmac('sha256', $vigilo_api_day . '|' . $vigilo_api_ip, $vigilo_secret), 0, 32);
            @mysqli_query($db, "INSERT INTO obs_api_visitors (stat_date, visitor_hash, visit_count) VALUES ('$vigilo_api_day', '$vigilo_visitor', 1) ON DUPLICATE KEY UPDATE visit_count = visit_count + 1");
        }
    }
}

# ACL
$acls        = array();
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while ($roles_result = mysqli_fetch_array($roles_query)) {
    $role          = $roles_result['role_name'];
    $acls[$role][] = $roles_result['role_key'];
}
# Observation status
$status_list = array(
    0 => array(
        "name" => "Nouvelle observation",
        "roles" => array(
            "admin"
        ),
        "nextstatus" => array(
            1,
            2,
            3,
            4
        )
    ),
    1 => array(
        "name" => "Observation résolue",
        "roles" => array(
            "admin"
        ),
        "nextstatus" => array(
            0
        )
    ),
    2 => array(
        "name" => "Observation prise en compte",
        "roles" => array(
            "admin",
            "citystaff"
        ),
        "nextstatus" => array(
            0,
            3,
            4
        )
    ),
    3 => array(
        "name" => "Observation en cours de résolution",
        "roles" => array(
            "admin",
            "citystaff"
        ),
        "nextstatus" => array(
            0,
            4
        )
    ),
    4 => array(
        "name" => "Observation indiquée comme résolue",
        "roles" => array(
            "admin",
            "citystaff"
        ),
        "nextstatus" => array(
            0,
            1
        )
    )
);
