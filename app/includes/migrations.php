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
 * Database migrations runner, shared by the Docker entrypoint (scripts/vigilo-migrate.php)
 * and the admin "Mises à jour" page, so every install type upgrades the same way.
 *
 * Migrations are app/migrations/init-X.Y.Z.sql, applied in version order. Each one is
 * recorded by setting obs_config.vigilo_db_version to its version as soon as it succeeds:
 * a failed run stops at the last good version and can be resumed.
 *
 * This file only defines functions: it does nothing when requested over HTTP.
 */

define('VIGILO_MIGRATIONS_DIR', dirname(__FILE__) . '/../migrations');

/* Version => file of every migration, sorted by version */
function vigilo_migrations_list($dir = VIGILO_MIGRATIONS_DIR)
{
    $migrations = array();
    foreach (glob($dir . '/init-*.sql') as $file) {
        if (preg_match('/init-(\d+\.\d+\.\d+)\.sql$/', $file, $m)) {
            $migrations[$m[1]] = $file;
        }
    }
    uksort($migrations, 'version_compare');
    return $migrations;
}

function vigilo_table_exists($db, $table)
{
    $query = mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $table) . "'");
    if ($query === false) {
        throw new Exception("Impossible de lister les tables : " . mysqli_error($db));
    }
    return mysqli_num_rows($query) == 1;
}

/*
 * Version of the database schema: '0.0.0' for an empty database.
 * Throws when it can not be read, so that a transient error is never taken for an
 * empty database (which would replay every migration).
 */
function vigilo_db_version($db)
{
    if (!vigilo_table_exists($db, 'obs_config')) {
        if (vigilo_table_exists($db, 'obs_list')) {
            throw new Exception("Table obs_config absente mais obs_list présente : version de la base inconnue");
        }
        return '0.0.0';
    }
    $query = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version' LIMIT 1");
    if ($query === false) {
        throw new Exception("Impossible de lire vigilo_db_version : " . mysqli_error($db));
    }
    $row = mysqli_fetch_array($query);
    if (!$row || !preg_match('/^\d+\.\d+\.\d+$/', $row['config_value'])) {
        throw new Exception("vigilo_db_version absente ou invalide dans obs_config");
    }
    return $row['config_value'];
}

/* Migrations newer than the database, up to (and including) $target */
function vigilo_migrations_pending($db_version, $target, $dir = VIGILO_MIGRATIONS_DIR)
{
    $pending = array();
    foreach (vigilo_migrations_list($dir) as $version => $file) {
        if (version_compare($version, $db_version, '>') && version_compare($version, $target, '<=')) {
            $pending[$version] = $file;
        }
    }
    return $pending;
}

/* SQL statements of a migration file (comments removed) */
function vigilo_migration_statements($file)
{
    $sql = '';
    foreach (file($file) as $line) {
        $trimmed = ltrim($line);
        // "-----" separators are not valid SQL comments for the server
        if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        $sql .= $line;
    }
    $statements = array();
    // Split on ";" at end of line: migrations have one statement per line or block
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            $statements[] = trim($statement);
        }
    }
    return $statements;
}

function vigilo_set_db_version($db, $version)
{
    if (!vigilo_table_exists($db, 'obs_config')) {
        return;
    }
    $version = mysqli_real_escape_string($db, $version);
    $query   = mysqli_query($db, "SELECT config_param FROM obs_config WHERE config_param='vigilo_db_version' LIMIT 1");
    if ($query && mysqli_num_rows($query) == 1) {
        mysqli_query($db, "UPDATE obs_config SET config_value='" . $version . "' WHERE config_param='vigilo_db_version'");
    } else {
        mysqli_query($db, "INSERT INTO obs_config (config_param, config_value) VALUES ('vigilo_db_version', '" . $version . "')");
    }
}

/*
 * Bring the database to $target (default: the code version).
 * Returns the list of applied versions; throws on error, after logging through $log.
 */
function vigilo_migrate($db, $target = null, $log = null, $dir = VIGILO_MIGRATIONS_DIR)
{
    if ($target === null) {
        $target = BACKEND_VERSION;
    }
    if ($log === null) {
        $log = function ($message) {
        };
    }

    // mysqli throws since PHP 8.1: keep the PHP 7 behaviour, errors are checked below
    mysqli_report(MYSQLI_REPORT_OFF);

    $lock = mysqli_query($db, "SELECT GET_LOCK('vigilo_migrate', 0)");
    if (!$lock || mysqli_fetch_array($lock)[0] != 1) {
        throw new Exception("Une migration est déjà en cours");
    }

    try {
        $db_version = vigilo_db_version($db);
        if (version_compare($db_version, $target, '>')) {
            throw new Exception("La base ($db_version) est plus récente que le code ($target) : retour arrière non supporté");
        }

        mysqli_query($db, "SET SESSION innodb_strict_mode=OFF");
        mysqli_query($db, "SET SESSION sql_mode=''");

        $applied = array();
        foreach (vigilo_migrations_pending($db_version, $target, $dir) as $version => $file) {
            $log("Migration $version");
            foreach (vigilo_migration_statements($file) as $statement) {
                if (!mysqli_query($db, $statement)) {
                    throw new Exception("Migration $version en échec : " . mysqli_error($db) . "\nRequête : " . $statement);
                }
            }
            vigilo_set_db_version($db, $version);
            $applied[] = $version;
        }

        // Code version without schema change: align the recorded version anyway
        if (version_compare(vigilo_db_version($db), $target, '<')) {
            vigilo_set_db_version($db, $target);
        }
        return $applied;
    } catch (Exception $e) {
        $log($e->getMessage());
        throw $e;
    } finally {
        mysqli_query($db, "SELECT RELEASE_LOCK('vigilo_migrate')");
    }
}
