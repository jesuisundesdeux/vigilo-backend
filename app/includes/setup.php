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
 * First install (admin/setup.php, Docker entrypoint): state of the instance without
 * includes/common.php (which stops when the configuration or the database is missing),
 * one-time install code, configuration file.
 *
 * The install code protects every step of the setup: it is written in a file of the
 * server (caches/ or config/), that only the person who installs can read (FTP, file
 * manager, "docker compose logs"). It is removed once the admin account exists.
 *
 * This file only defines functions: it does nothing when requested over HTTP.
 */

define('VIGILO_APP_DIR', realpath(dirname(__FILE__) . '/..'));
define('VIGILO_SETUP_CODE_FILE', 'setup-code.php');

/* Docker image: the configuration comes from the environment (config.php.docker) */
function vigilo_setup_is_docker()
{
    return getenv('VIGILO_RUNTIME') === 'docker';
}

function vigilo_setup_config_file($app = VIGILO_APP_DIR)
{
    return $app . '/config/config.php';
}

/* Database settings of config/config.php, null if the file is missing */
function vigilo_setup_read_config($app = VIGILO_APP_DIR)
{
    $file = vigilo_setup_config_file($app);
    if (!file_exists($file)) {
        return null;
    }
    $config = array();
    include $file;
    return $config;
}

/* Connection with these settings: mysqli, or the error message */
function vigilo_setup_connect($settings)
{
    mysqli_report(MYSQLI_REPORT_OFF);
    foreach (array('MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE') as $key) {
        if (!isset($settings[$key]) || $settings[$key] === false) {
            return 'Paramètre ' . $key . ' manquant.';
        }
    }
    $host = (string) $settings['MYSQL_HOST'];
    $port = null;
    // "host:port"
    if (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = intval($m[2]);
    }
    $db = @mysqli_init();
    if (!$db) {
        return 'Extension mysqli indisponible.';
    }
    mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    if (!@mysqli_real_connect($db, $host, (string) $settings['MYSQL_USER'], (string) $settings['MYSQL_PASSWORD'], (string) $settings['MYSQL_DATABASE'], $port)) {
        return 'Connexion impossible : ' . mysqli_connect_error();
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/*
 * Checks that the account can create the tables: creates and drops a test table.
 * Returns '' or the error.
 */
function vigilo_setup_check_privileges($db)
{
    if (!mysqli_query($db, "CREATE TABLE IF NOT EXISTS vigilo_setup_check (id int NOT NULL) ENGINE=InnoDB")) {
        return 'Le compte ne peut pas créer de table : ' . mysqli_error($db);
    }
    if (!mysqli_query($db, "ALTER TABLE vigilo_setup_check ADD COLUMN check_col int NULL")) {
        $error = mysqli_error($db);
        mysqli_query($db, "DROP TABLE IF EXISTS vigilo_setup_check");
        return 'Le compte ne peut pas modifier de table : ' . $error;
    }
    if (!mysqli_query($db, "DROP TABLE vigilo_setup_check")) {
        return 'Le compte ne peut pas supprimer de table : ' . mysqli_error($db);
    }
    return '';
}

/* Admin accounts that can log in (login and password) */
function vigilo_setup_admin_count($db)
{
    if (!vigilo_table_exists($db, 'obs_roles')) {
        return 0;
    }
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_roles WHERE role_name = 'admin' AND role_login <> '' AND role_password <> ''");
    return $query ? intval(mysqli_fetch_array($query)[0]) : 0;
}

/*
 * State of the instance:
 * - config: config/config.php present;
 * - db: mysqli or null, db_error;
 * - db_version (null if unknown), app_version;
 * - admin: number of admin accounts that can log in;
 * - step: 'config' (no configuration or no connection), 'database' (empty or older
 *   database), 'newer' (database newer than the code), 'admin' (no admin account),
 *   'done'.
 */
function vigilo_setup_state($app = VIGILO_APP_DIR)
{
    $state = array('config' => false, 'db' => null, 'db_error' => '', 'db_version' => null,
                   'app_version' => BACKEND_VERSION, 'admin' => 0, 'step' => 'config');
    $settings = vigilo_setup_read_config($app);
    if ($settings === null) {
        return $state;
    }
    $state['config'] = true;
    $db = vigilo_setup_connect($settings);
    if (!($db instanceof mysqli)) {
        $state['db_error'] = $db;
        return $state;
    }
    $state['db'] = $db;
    try {
        $state['db_version'] = vigilo_db_version($db);
    } catch (Exception $e) {
        $state['db_error'] = $e->getMessage();
        return $state;
    }
    if (version_compare($state['db_version'], BACKEND_VERSION, '>')) {
        $state['step'] = 'newer';
        return $state;
    }
    $state['admin'] = vigilo_setup_admin_count($db);
    if ($state['db_version'] === '0.0.0' || ($state['admin'] == 0 && version_compare($state['db_version'], BACKEND_VERSION, '<'))) {
        // Empty database, or older one before the first admin account: migrated by the setup.
        // Once an admin exists, the updates go through the admin ("Mises à jour") or Docker.
        $state['step'] = 'database';
    } elseif ($state['admin'] == 0) {
        $state['step'] = 'admin';
    } else {
        $state['step'] = 'done';
    }
    return $state;
}

/* Is the setup needed (admin/login.php sends there)? Cheap enough for the login page. */
function vigilo_setup_needed($app = VIGILO_APP_DIR)
{
    $state = vigilo_setup_state($app);
    if ($state['db'] instanceof mysqli) {
        mysqli_close($state['db']);
    }
    return $state['step'] !== 'done';
}

/* Directories where the install code can be written, in order of preference */
function vigilo_setup_code_dirs($app = VIGILO_APP_DIR)
{
    // caches/ is closed to the web (.htaccess, Docker), config/ is a fallback
    return array($app . '/caches', $app . '/config');
}

/* Current install code and its file, or array(null, null) */
function vigilo_setup_code_read($app = VIGILO_APP_DIR)
{
    foreach (vigilo_setup_code_dirs($app) as $dir) {
        $file = $dir . '/' . VIGILO_SETUP_CODE_FILE;
        if (is_file($file) && preg_match('/^([A-Z0-9]{24})$/m', (string) @file_get_contents($file), $m)) {
            return array($m[1], $file);
        }
    }
    return array(null, null);
}

/*
 * Install code, created if needed. Returns array(code, file), or array(null, error) when
 * no directory is writable.
 */
function vigilo_setup_code_ensure($app = VIGILO_APP_DIR)
{
    list($code, $file) = vigilo_setup_code_read($app);
    if ($code !== null) {
        return array($code, $file);
    }
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code     = '';
    for ($i = 0; $i < 24; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    // A PHP file that never shows its content, even when the web server serves the directory
    $content = "<?php http_response_code(404); exit; ?>\n" . $code . "\n";
    foreach (vigilo_setup_code_dirs($app) as $dir) {
        $file = $dir . '/' . VIGILO_SETUP_CODE_FILE;
        if (is_dir($dir) && is_writable($dir) && @file_put_contents($file, $content, LOCK_EX) !== false) {
            @chmod($file, 0640);
            return array($code, $file);
        }
    }
    return array(null, 'Aucun des dossiers caches/ et config/ n\'est accessible en écriture : rendez caches/ accessible en écriture au serveur web.');
}

function vigilo_setup_code_valid($given, $app = VIGILO_APP_DIR)
{
    list($code, $file) = vigilo_setup_code_read($app);
    $given = strtoupper(preg_replace('/[\s-]+/', '', (string) $given));
    return $code !== null && $given !== '' && hash_equals($code, $given);
}

function vigilo_setup_code_remove($app = VIGILO_APP_DIR)
{
    foreach (vigilo_setup_code_dirs($app) as $dir) {
        @unlink($dir . '/' . VIGILO_SETUP_CODE_FILE);
    }
}

/* Content of config/config.php for these database settings */
function vigilo_setup_config_content($settings)
{
    $lines = array("<?php", "/* Database configuration, written by the install assistant (admin/setup.php) */", "");
    foreach (array('MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE') as $key) {
        $lines[] = "\$config['" . $key . "'] = " . var_export((string) $settings[$key], true) . ";";
    }
    return implode("\n", $lines) . "\n";
}

/* Writes config/config.php; returns '' or the error */
function vigilo_setup_write_config($settings, $app = VIGILO_APP_DIR)
{
    $file = vigilo_setup_config_file($app);
    if (file_exists($file)) {
        return 'Le fichier config/config.php existe déjà.';
    }
    if (!is_writable(dirname($file)) || @file_put_contents($file, vigilo_setup_config_content($settings), LOCK_EX) === false) {
        return 'Le dossier config/ n\'est pas accessible en écriture.';
    }
    @chmod($file, 0640);
    return '';
}

/*
 * URL of the instance seen by the browser: array(base without protocol, protocol), from
 * the address of a page in admin/ (base = host + path above admin/).
 */
function vigilo_setup_detect_url()
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    $path = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    $path = rtrim(str_replace('\\', '/', dirname(dirname($path))), '/');
    $path = preg_replace('#[^A-Za-z0-9._~/\-]#', '', $path);
    return array($host . $path, vigilo_is_https() ? 'https' : 'http');
}

/* PHP requirements: label => array(ok, detail) */
function vigilo_setup_requirements($app = VIGILO_APP_DIR)
{
    $checks = array(
        'PHP 7.3 ou plus récent' => array(version_compare(PHP_VERSION, '7.3.0', '>='), PHP_VERSION),
    );
    foreach (array('mysqli' => true, 'gd' => true, 'exif' => true, 'curl' => false, 'zip' => false) as $ext => $required) {
        $checks['Extension ' . $ext . ($required ? '' : ' (mises à jour depuis l\'admin)')] = array(extension_loaded($ext) ? true : ($required ? false : null), extension_loaded($ext) ? 'présente' : 'absente');
    }
    foreach (array('images', 'images/resolutions', 'caches') as $dir) {
        $path = $app . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $checks['Dossier ' . $dir . '/ accessible en écriture'] = array(is_dir($path) && is_writable($path), is_dir($path) ? (is_writable($path) ? 'oui' : 'non') : 'absent');
    }
    return $checks;
}
