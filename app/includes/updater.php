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
 * Updates from the admin.
 *
 * - Classic install (shared hosting, dedicated server): the release archive published on
 *   GitHub is downloaded, checked (SHA-256, and ed25519 signature when a public key is
 *   configured), the code and the database are backed up, the files are replaced, the
 *   migrations are applied and the instance is checked; on failure everything is
 *   restored.
 * - Docker: the code belongs to the image, it is never modified in the container (the
 *   change would be lost at the next restart). The image is updated by Watchtower, from
 *   the admin when its HTTP API is configured, or with docker compose.
 */

require_once(dirname(__FILE__) . '/migrations.php');
require_once(dirname(__FILE__) . '/release_key.php');

define('VIGILO_REPOSITORY', 'jesuisundesdeux/vigilo-backend');

/* Paths that belong to the instance and are never replaced nor backed up as code */
function vigilo_update_preserved()
{
    return array('config/config.php', 'images', 'caches', 'maps', 'install.php');
}

function vigilo_app_root()
{
    return realpath(dirname(__FILE__) . '/..');
}

function vigilo_runtime()
{
    if (getenv('VIGILO_RUNTIME') === 'docker' || file_exists('/.dockerenv')) {
        return 'docker';
    }
    return 'classic';
}

function vigilo_updates_dir()
{
    global $config;
    $dir = vigilo_app_root() . '/' . $config['DATA_PATH'] . 'caches/updates';
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Backups hold the whole database: never served by the web server
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/* Latest release published on GitHub (cached 1 hour) */
function vigilo_latest_release($force = false)
{
    $cache = vigilo_updates_dir() . '/latest-release.json';
    if (!$force && file_exists($cache) && filemtime($cache) > time() - 3600) {
        $release = json_decode(file_get_contents($cache), true);
        if (is_array($release)) {
            return $release;
        }
    }

    $release = null;
    // VIGILO_RELEASE_API: other source of releases (tests, mirror)
    $api     = getenv('VIGILO_RELEASE_API') ? getenv('VIGILO_RELEASE_API') : 'https://api.github.com/repos/' . VIGILO_REPOSITORY . '/releases/latest';
    $data    = getWebContent($api);
    $json    = $data ? json_decode($data, true) : null;
    if (is_array($json) && isset($json['tag_name']) && preg_match('/^v(\d+\.\d+\.\d+)$/', $json['tag_name'], $m)) {
        $assets = array();
        foreach ((isset($json['assets']) ? $json['assets'] : array()) as $asset) {
            $assets[$asset['name']] = $asset['browser_download_url'];
        }
        $release = array(
            'version' => $m[1],
            'name' => isset($json['name']) ? $json['name'] : $json['tag_name'],
            'notes' => isset($json['body']) ? $json['body'] : '',
            'url' => isset($json['html_url']) ? $json['html_url'] : '',
            'published_at' => isset($json['published_at']) ? $json['published_at'] : '',
            'assets' => $assets
        );
    } elseif (!getenv('VIGILO_RELEASE_API')) {
        // No release yet: latest version tag, without archive
        $tags = getWebContent('https://api.github.com/repos/' . VIGILO_REPOSITORY . '/tags');
        $tags = $tags ? json_decode($tags, true) : null;
        $best = null;
        foreach ((is_array($tags) ? $tags : array()) as $tag) {
            if (isset($tag['name']) && preg_match('/^v(\d+\.\d+\.\d+)$/', $tag['name'], $m) && ($best === null || version_compare($m[1], $best, '>'))) {
                $best = $m[1];
            }
        }
        if ($best !== null) {
            $release = array(
                'version' => $best,
                'name' => 'v' . $best,
                'notes' => '',
                'url' => 'https://github.com/' . VIGILO_REPOSITORY . '/releases/tag/v' . $best,
                'published_at' => '',
                'assets' => array()
            );
        }
    }

    if ($release) {
        @file_put_contents($cache, json_encode($release));
    }
    return $release;
}

function vigilo_release_archive_name($version)
{
    return 'vigilo-backend-' . $version . '.zip';
}

/* What prevents the automatic update of the code, empty when it is possible */
function vigilo_update_blockers($release)
{
    $blockers = array();
    if (vigilo_runtime() == 'docker') {
        $blockers[] = "Instance Docker : le code fait partie de l'image, il se met à jour en changeant d'image.";
        return $blockers;
    }
    if (!$release || !isset($release['assets'][vigilo_release_archive_name($release['version'])])) {
        $blockers[] = "Cette version n'est pas publiée avec une archive de mise à jour.";
    }
    if (!class_exists('ZipArchive')) {
        $blockers[] = "L'extension PHP zip n'est pas installée.";
    }
    if (!function_exists('curl_init')) {
        $blockers[] = "L'extension PHP curl n'est pas installée.";
    }
    $root = vigilo_app_root();
    foreach (array($root, $root . '/includes', $root . '/admin', $root . '/migrations') as $dir) {
        if (!is_writable($dir)) {
            $blockers[] = "Le serveur web ne peut pas écrire dans " . $dir . ".";
            break;
        }
    }
    return $blockers;
}

function vigilo_download($url, $path)
{
    $fp = fopen($path, 'wb');
    if (!$fp) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Vigilo-Backend/' . BACKEND_VERSION);
    $ok   = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $ok && $code == 200 && filesize($path) > 0;
}

/* Recursive copy, skipping the preserved paths (relative to $base) */
function vigilo_copy_tree($src, $dst, $skip = array(), $base = '')
{
    if (!file_exists($dst) && !@mkdir($dst, 0775, true)) {
        throw new Exception("Impossible de créer le répertoire $dst");
    }
    foreach (scandir($src) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        $rel = ltrim($base . '/' . $item, '/');
        if (in_array($rel, $skip, true)) {
            continue;
        }
        if (is_dir($src . '/' . $item)) {
            vigilo_copy_tree($src . '/' . $item, $dst . '/' . $item, $skip, $rel);
        } else {
            // Copy then rename: a request never reads a half-written file
            $tmp = $dst . '/.' . $item . '.tmp-update';
            if (!@copy($src . '/' . $item, $tmp) || !@rename($tmp, $dst . '/' . $item)) {
                @unlink($tmp);
                throw new Exception("Impossible d'écrire " . $dst . '/' . $item);
            }
        }
    }
}

function vigilo_remove_tree($dir)
{
    if (!file_exists($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? vigilo_remove_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/* SQL dump of the obs_* tables, written by PHP (mysqldump is rarely available) */
function vigilo_dump_database($db, $path)
{
    $fp = fopen($path, 'wb');
    if (!$fp) {
        throw new Exception("Impossible d'écrire la sauvegarde $path");
    }
    fwrite($fp, "-- Vigilo " . BACKEND_VERSION . " backup " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET SESSION sql_mode='';\n");
    $tables = mysqli_query($db, "SHOW TABLES LIKE 'obs\\_%'");
    while ($tables && $table = mysqli_fetch_array($tables)) {
        $name   = $table[0];
        $create = mysqli_fetch_array(mysqli_query($db, "SHOW CREATE TABLE `$name`"));
        fwrite($fp, "DROP TABLE IF EXISTS `$name`;\n" . $create[1] . ";\n");
        $rows = mysqli_query($db, "SELECT * FROM `$name`", MYSQLI_USE_RESULT);
        while ($rows && $row = mysqli_fetch_assoc($rows)) {
            $values = array();
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : "'" . mysqli_real_escape_string($db, $value) . "'";
            }
            fwrite($fp, "INSERT INTO `$name` VALUES (" . implode(',', $values) . ");\n");
        }
        if ($rows) {
            mysqli_free_result($rows);
        }
    }
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
}

function vigilo_restore_database($db, $path)
{
    $sql = file_get_contents($path);
    if (!mysqli_multi_query($db, $sql)) {
        throw new Exception("Restauration de la base impossible : " . mysqli_error($db));
    }
    do {
        if ($result = mysqli_store_result($db)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($db) && mysqli_next_result($db));
    if (mysqli_errno($db)) {
        throw new Exception("Restauration de la base incomplète : " . mysqli_error($db));
    }
}

/* Version answered by the instance itself over HTTP, false if it does not answer */
function vigilo_health_check()
{
    global $config;
    if (empty($config['URLBASE'])) {
        return null;
    }
    $proto = !empty($config['HTTP_PROTOCOL']) ? $config['HTTP_PROTOCOL'] : 'https';
    $data  = getWebContent($proto . '://' . $config['URLBASE'] . '/get_version.php?nocache=' . time());
    $json  = $data ? json_decode($data, true) : null;
    return (is_array($json) && isset($json['version'])) ? $json['version'] : false;
}

/*
 * Installs a release. $log receives the steps. Returns the installed version or throws.
 */
function vigilo_install_release($db, $release, $log)
{
    @set_time_limit(600);
    ignore_user_abort(true);

    $blockers = vigilo_update_blockers($release);
    if ($blockers) {
        throw new Exception(implode(' ', $blockers));
    }
    $version = $release['version'];
    if (version_compare($version, BACKEND_VERSION, '<=')) {
        throw new Exception("La version $version n'est pas plus récente que la version installée (" . BACKEND_VERSION . ").");
    }

    $dir     = vigilo_updates_dir();
    $archive = $dir . '/' . vigilo_release_archive_name($version);
    $root    = vigilo_app_root();

    // 1. Download and check
    $log("Téléchargement de la version $version");
    if (!vigilo_download($release['assets'][vigilo_release_archive_name($version)], $archive)) {
        throw new Exception("Téléchargement de l'archive impossible.");
    }
    if (!isset($release['assets']['SHA256SUMS']) || !vigilo_download($release['assets']['SHA256SUMS'], $archive . '.sha256')) {
        throw new Exception("Somme de contrôle de l'archive introuvable.");
    }
    $expected = null;
    foreach (file($archive . '.sha256') as $line) {
        if (preg_match('/^([a-f0-9]{64})\s+\*?(\S+)$/', trim($line), $m) && $m[2] == vigilo_release_archive_name($version)) {
            $expected = $m[1];
        }
    }
    if (!$expected || !hash_equals($expected, hash_file('sha256', $archive))) {
        throw new Exception("L'archive téléchargée ne correspond pas à la somme de contrôle publiée.");
    }
    $log("Somme de contrôle SHA-256 vérifiée");

    if (VIGILO_RELEASE_PUBLIC_KEY !== '') {
        $sig_name = vigilo_release_archive_name($version) . '.sig';
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new Exception("L'extension PHP sodium est nécessaire pour vérifier la signature.");
        }
        if (!isset($release['assets'][$sig_name]) || !vigilo_download($release['assets'][$sig_name], $archive . '.sig')) {
            throw new Exception("Signature de l'archive introuvable.");
        }
        $signature = base64_decode(trim(file_get_contents($archive . '.sig')), true);
        $key       = base64_decode(VIGILO_RELEASE_PUBLIC_KEY, true);
        if (!$signature || !$key || !sodium_crypto_sign_verify_detached($signature, file_get_contents($archive), $key)) {
            throw new Exception("La signature de l'archive est invalide : mise à jour refusée.");
        }
        $log("Signature ed25519 vérifiée");
    }

    // 2. Extract and check the requirements of the new version
    $stage = $dir . '/stage-' . $version;
    vigilo_remove_tree($stage);
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new Exception("Archive illisible.");
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, '..') !== false || substr($name, 0, 1) == '/') {
            $zip->close();
            throw new Exception("Archive invalide (chemin $name).");
        }
    }
    $zip->extractTo($stage);
    $zip->close();

    $manifest = file_exists($stage . '/manifest.json') ? json_decode(file_get_contents($stage . '/manifest.json'), true) : null;
    if (!is_array($manifest) || !isset($manifest['version']) || $manifest['version'] !== $version || !is_dir($stage . '/app')) {
        throw new Exception("Archive invalide (manifest).");
    }
    if (isset($manifest['min_php']) && version_compare(PHP_VERSION, $manifest['min_php'], '<')) {
        throw new Exception("La version $version demande PHP " . $manifest['min_php'] . " ou plus récent (installé : " . PHP_VERSION . ").");
    }
    if (isset($manifest['required_extensions'])) {
        foreach ($manifest['required_extensions'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new Exception("La version $version demande l'extension PHP $ext.");
            }
        }
    }
    $log("Archive extraite, prérequis vérifiés (PHP " . PHP_VERSION . ")");

    // 3. Backups
    $backup = $dir . '/backup-' . BACKEND_VERSION . '-' . date('Ymd-His');
    vigilo_copy_tree($root, $backup . '/app', array_merge(vigilo_update_preserved(), array('admin/assets/vendor/.keep')));
    vigilo_dump_database($db, $backup . '/database.sql');
    $log("Sauvegarde du code et de la base : " . basename($backup));

    // 4. Install
    $db_before = vigilo_db_version($db);
    try {
        vigilo_copy_tree($stage . '/app', $root, vigilo_update_preserved());
        $log("Fichiers de la version $version installés");
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $applied = vigilo_migrate($db, $version, $log);
        $log(count($applied) ? "Migrations appliquées : " . implode(', ', $applied) : "Aucune migration de base nécessaire");

        // 5. Check that the instance answers with the new version
        $answered = vigilo_health_check();
        if ($answered === false || ($answered !== null && $answered !== $version)) {
            throw new Exception("L'instance ne répond pas correctement après la mise à jour" . ($answered ? " (version $answered)" : '') . ".");
        }
        $log($answered === null ? "Vérification HTTP ignorée (URL de l'instance non configurée)" : "L'instance répond en version $answered");
    } catch (Exception $e) {
        $log("Échec : " . $e->getMessage() . " Restauration de la version " . BACKEND_VERSION . "…");
        vigilo_copy_tree($backup . '/app', $root, vigilo_update_preserved());
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (vigilo_db_version($db) !== $db_before) {
            vigilo_restore_database($db, $backup . '/database.sql');
            $log("Base de données restaurée");
        }
        throw new Exception($e->getMessage() . " La version précédente a été restaurée.");
    }

    // Files of the previous versions that do not exist anymore (still in the backup)
    if (isset($manifest['obsolete_paths']) && is_array($manifest['obsolete_paths'])) {
        foreach ($manifest['obsolete_paths'] as $obsolete) {
            $obsolete = trim((string) $obsolete, '/');
            if ($obsolete === '' || strpos($obsolete, '..') !== false || in_array(strtok($obsolete, '/'), vigilo_update_preserved(), true)
                || in_array($obsolete, vigilo_update_preserved(), true) || file_exists($stage . '/app/' . $obsolete)) {
                continue;
            }
            $path = $root . '/' . $obsolete;
            if (is_dir($path) && !is_link($path)) {
                vigilo_remove_tree($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    vigilo_remove_tree($stage);
    @unlink($archive);
    // Keep the 3 most recent backups
    $backups = glob($dir . '/backup-*', GLOB_ONLYDIR);
    sort($backups);
    while (count($backups) > 3) {
        vigilo_remove_tree(array_shift($backups));
    }
    return $version;
}

/* Docker: Watchtower HTTP API (https://containrrr.dev/watchtower/http-api-mode/) */
function vigilo_watchtower_configured()
{
    return getenv('VIGILO_WATCHTOWER_URL') && getenv('VIGILO_WATCHTOWER_TOKEN');
}

function vigilo_watchtower_update()
{
    $ch = curl_init(rtrim(getenv('VIGILO_WATCHTOWER_URL'), '/') . '/v1/update');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . getenv('VIGILO_WATCHTOWER_TOKEN')));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    // Watchtower answers when the update is finished, or never if it restarts us
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($code == 401) {
        throw new Exception("Watchtower refuse le jeton (VIGILO_WATCHTOWER_TOKEN).");
    }
    if ($errno && $errno != 28) {
        throw new Exception("Watchtower injoignable à " . getenv('VIGILO_WATCHTOWER_URL') . ".");
    }
    return true;
}

/*
 * Security checks shown in the admin: each item is array(level, title, detail),
 * level being danger, warning or success.
 */
function vigilo_security_checks()
{
    global $config;
    $checks = array();
    $root   = vigilo_app_root();
    $base   = (!empty($config['HTTP_PROTOCOL']) ? $config['HTTP_PROTOCOL'] : 'https') . '://' . (isset($config['URLBASE']) ? $config['URLBASE'] : '');

    if (file_exists($root . '/install.php')) {
        $checks[] = array('danger', "Le fichier install.php est présent", "Supprimez-le : il ne doit plus être accessible une fois l'instance installée.");
    }

    if (!empty($config['URLBASE'])) {
        foreach (array('images', 'caches') as $dir) {
            $ch = curl_init($base . '/' . $dir . '/index.html');
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code == 200) {
                $checks[] = array('danger', "Le répertoire $dir/ est accessible depuis le web",
                    "Les photos originales (non modérées, non floutées) peuvent être téléchargées directement. Sous nginx ajoutez : location ~ ^/(images|caches)/ { deny all; return 403; } — sous Apache vérifiez que AllowOverride autorise les fichiers .htaccess.");
            } elseif ($code == 403 || $code == 404) {
                $checks[] = array('success', "Le répertoire $dir/ n'est pas accessible depuis le web", '');
            }
        }
        if ($config['HTTP_PROTOCOL'] !== 'https') {
            $checks[] = array('warning', "L'instance n'est pas configurée en HTTPS", "Les navigateurs bloquent une API en HTTP appelée depuis l'application web (app.vigilo.city).");
        }
    }

    if (version_compare(PHP_VERSION, '8.1', '<')) {
        $checks[] = array('warning', "PHP " . PHP_VERSION . " n'est plus maintenu", "Passez à PHP 8.2 ou plus récent : Vigilo est compatible.");
    }
    if (ini_get('display_errors') && strtolower(ini_get('display_errors')) !== 'off' && ini_get('display_errors') !== '0') {
        $checks[] = array('warning', "Les erreurs PHP sont affichées", "Mettez display_errors à Off en production (les messages peuvent révéler des chemins ou des requêtes).");
    }
    return $checks;
}
