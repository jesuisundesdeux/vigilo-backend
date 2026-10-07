<?php
/*
 * Command line database migration, used by the Docker entrypoint and usable on a
 * dedicated server:
 *
 *   php scripts/vigilo-migrate.php [--app=/var/www/html] [--status]
 *
 * --status only prints "<db version> <code version>" and exits.
 * Exit codes: 0 up to date / migrated, 1 error, 2 database newer than the code.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', array('app:', 'status'));
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

if (version_compare($db_version, BACKEND_VERSION, '>')) {
    fwrite(STDERR, "La base ($db_version) est plus récente que le code (" . BACKEND_VERSION . ") : refus de démarrer sur une version antérieure\n");
    exit(2);
}

try {
    $applied = vigilo_migrate($db, BACKEND_VERSION, function ($message) {
        echo $message . "\n";
    });
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo count($applied) ? "Base migrée de $db_version à " . BACKEND_VERSION . "\n" : "Base à jour (" . BACKEND_VERSION . ")\n";
exit(0);
