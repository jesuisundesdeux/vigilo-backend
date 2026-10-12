<?php
/*
 * Command line database migration, used by the Docker entrypoint and usable on a
 * dedicated server:
 *
 *   php scripts/vigilo-migrate.php [--app=/var/www/html] [--status] [--to=X.Y.Z] [--setup-code]
 *
 * --status only prints "<db version> <code version>" and exits.
 * --setup-code prints "<install code> <file>" of the setup assistant (admin/setup.php) while
 * no admin account exists (created if needed), nothing otherwise or when the host sets it
 * (VIGILO_SETUP_CODE).
 * --to stops at an older version than the code (tests, step by step upgrades).
 * Exit codes: 0 up to date / migrated, 1 error, 2 database newer than the code.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', array('app:', 'status', 'to:', 'setup-code'));
$app     = isset($options['app']) ? $options['app'] : dirname(__FILE__) . '/../app';

require_once($app . '/includes/version.php');
require_once($app . '/includes/migrations.php');

$config = array();
require_once($app . '/config/config.php');

mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_connect($config['MYSQL_HOST'], $config['MYSQL_USER'], $config['MYSQL_PASSWORD'], $config['MYSQL_DATABASE']);
if (!$db) {
    fwrite(STDERR, "Connexion à la base impossible : " . mysqli_connect_error() . "\n");
    exit(1);
}
if (isset($config['MYSQL_CHARSET'])) {
    mysqli_set_charset($db, $config['MYSQL_CHARSET']);
}

try {
    $db_version = vigilo_db_version($db);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (isset($options['status'])) {
    echo $db_version . ' ' . BACKEND_VERSION . "\n";
    exit(0);
}

// Install code of the setup assistant (admin/setup.php), printed while no admin account exists
if (isset($options['setup-code'])) {
    require_once($app . '/includes/setup.php');
    if (vigilo_setup_admin_count($db) > 0) {
        exit(0);
    }
    list($code, $file) = vigilo_setup_code_ensure($app);
    if ($code !== null && $file === '') {
        // VIGILO_SETUP_CODE: known by the host, never printed in the logs
        exit(0);
    }
    if ($code === null) {
        fwrite(STDERR, $file . "\n");
        exit(1);
    }
    echo $code . ' ' . $file . "\n";
    exit(0);
}

if (version_compare($db_version, BACKEND_VERSION, '>')) {
    fwrite(STDERR, "La base ($db_version) est plus récente que le code (" . BACKEND_VERSION . ") : refus de démarrer sur une version antérieure\n");
    exit(2);
}

$target = isset($options['to']) ? $options['to'] : BACKEND_VERSION;
if (!preg_match('/^\d+\.\d+\.\d+$/', $target) || version_compare($target, BACKEND_VERSION, '>')) {
    fwrite(STDERR, "Version cible invalide : $target\n");
    exit(1);
}

try {
    $applied = vigilo_migrate($db, $target, function ($message) {
        echo $message . "\n";
    });
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo count($applied) ? "Base migrée de $db_version à $target\n" : "Base à jour ($target)\n";
exit(0);
