<?php

namespace Migration;

use PharData;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;

/**
 * Implements migration plan with each steps and maintenance action.
 */
class Plan extends Common
{
  public const RELEASE_BASE_URL = 'https://vigilo-git.test/fake/%s';
  public const ARCHIVE_BASE_URL = 'https://vigilo-git.test/fake/vigilo-backend-%s.tar.gz';

  public const STEPS = [
    'UP' => [
      'getTarball' => 'Téléchargement de la release',
      'extractApp' => "Extraction du code source de l'application",
      'dbBackup' => 'Sauvegarde de la base de données',
      'dbUpdate' => 'Mise à jour de la base de données',
      // 'deploy' => 'Copie des fichiers',
      // 'cleanup' => 'Nettoyage'
    ],
    'DOWN' => []
  ];
  public const maintenance_steps = [
    'maintenance_on' => 'Activation du mode maintenance',
    'maintenance_off' => 'Désactivation du mode maintenance',
  ];

  /**
   * Method execute before migration plan steps when enabling maintenance ;
   * depends $maintenance parameter.
   */
  public function maintenance_on(): array
  {
    return [0, 'maintenance enable'];
  }

  /**
   * Method execute at the end of migration plan when disabling maintenance
   * depends $maintenance parameter.
   */
  public function maintenance_off(): array
  {
    return [0, 'maintenance disable'];
  }

  /**
   * download release
   * @return array errno, err_msg, step_name
   */
  public function getTarball(): array
  {
    // TODO: specific version
    $releaseJson = getWebContent(sprintf(static::RELEASE_BASE_URL, 'vigilo-backend-latest.json'));
    $releaseInfo = json_decode($releaseJson, true);
    $release = ltrim($releaseInfo['tag_name'], 'v');
    $this->state->append('release_version', $release);
    $archiveURL = sprintf(static::ARCHIVE_BASE_URL, $release);
    // define new constant MGRT_archive_release_filename shared through all migration steps
    $this->state->append('archive_release', MGRT_instance_d . sprintf(static::ARCHIVE_FILENAME, $release));
    saveWebContent($archiveURL, MGRT_archive_release);

    return [0, 'download tarball'];
  }

  public function extractApp(): array
  {
    $archive = new PharData(MGRT_archive_release);
    $phar_prefix_d = $archive->getPathname() . '/';
    // Build applications init files list
    $files_from_archive = [];
    $sql_init_files = [];
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
      // app/* files list
      $match = $phar_prefix_d . 'app/';
      if (str_starts_with($entry->getPathname(), $match)) {
        $files_from_archive[] = str_replace($phar_prefix_d . 'app/', '', $entry->getPathname());
        continue;
      }
      // SQL init scripts list
      $match = $phar_prefix_d . 'mysql/init/';
      if (str_starts_with($entry->getPathname(), $match)) {
        $sql_init_files[] = str_replace($phar_prefix_d, '', $entry->getPathname());
        continue;
      }
    }

    // compute current app folder tree
    $current_app_iterator = new RecursiveDirectoryIterator(self::ROOTD(), RecursiveDirectoryIterator::SKIP_DOTS);
    $current_app_filtered = new RecursiveCallbackFilterIterator($current_app_iterator, [$this, 'current_app_filters']);
    // filtering current app files, remove admin/upgrade/work/* and generated
    // images from images, maps and caches folder
    $files_installed = [];
    foreach (new RecursiveIteratorIterator($current_app_filtered) as $object) {
      // $files_installed[] = preg_replace('!^(.*app/)(.*$)!', '\2', $object->getPathname());
      $files_installed[] = preg_replace('!^(' . self::ROOTD() . ')(.*$)!', '\2', $object->getPathname());
    }

    // create rollback
    // files that is no part of this release can be files remove from previous
    // release or custom files.
    $extra_current_files = array_diff($files_installed, $files_from_archive);
    sort($extra_current_files);
    // files that needs backup because there are different from release
    $files_need_backup = array_filter($files_from_archive, function ($node) use ($phar_prefix_d) {
      // append only files already exist and with a different content
      if (file_exists(self::ROOTD() . $node)
          && (md5_file(self::ROOTD() . $node) !== md5_file($phar_prefix_d . 'app/' . $node))
      ) {
        return true;
      }
      return false;
    });

    // define rollback folder name
    $rollback_name = sprintf('rollback-%s', MGRT_backend_version_before);
    $rollback_d = MGRT_instance_d . $rollback_name . '/';
    // append (backup) extra files to rollback folder
    $this->copy_w_mkdir($extra_current_files, $rollback_d, self::ROOTD());
    // append (backup and after install) release files
    $this->copy_w_mkdir($files_need_backup, self::ROOTD(), $phar_prefix_d . 'app/', $rollback_d);

    // SQL init scripts list filtering
    natcasesort($sql_init_files);
    // Filter versions ; keep version greater than mine.
    $keepSQL = array_filter($sql_init_files, function ($scriptFile) {
      $scriptVersion = preg_replace('!^mysql/init/init-|\.sql$!', '', $scriptFile);
      return version_compare($scriptVersion, MGRT_backend_version_before, '>');
    });
    // append sql init folder
    if (count($keepSQL)) {
      mkdir(MGRT_instance_d . 'sql', 0755, true);
    }
    // copy sql migration files
    foreach ($keepSQL as $filename) {
      $sql_path_renamed = preg_replace('!^mysql/init/?!', 'sql', dirname($filename)) . '/' . basename($filename);
      copy($phar_prefix_d . $filename, MGRT_instance_d . $sql_path_renamed);
    }

    return [0, 'extraction OK'];
  }

  public function deploy()
  {
    // rename current application version
    $old_renamed = rtrim(self::ROOTD(), '/') . '-' . MGRT_backend_version_before;
    $tmp_new = rtrim(self::ROOTD(), '/') . '-new';
    // copy configuration file to new
    copy(self::ROOTD() . 'config/config.php', MGRT_instance_d . 'app/config/config.php');

    // move new from working folder to $tmp_new
    rename(MGRT_instance_d . 'app', $tmp_new);
    // rename old to app-its-version
    rename(self::ROOTD(), $old_renamed);
    // new become the application
    rename($tmp_new, self::ROOTD());
    // create instance working folder
    mkdir(MGRT_instance_d, 0775, true);
    // move old (renamed) to working dir
    // rename($old_renamed, MGRT_instance_d . 'app-' . MGRT_backend_version_before);

    return [0, 'deploy ok'];
  }

  /**
   */
  public function dbBackup(): array
  {
    if (!defined('config')) {
      require self::ROOTD() . 'config/config.php';
    }

    $dumpFilePath = MGRT_instance_d . static::SQL_DUMP_FILENAME;
    exec(
      sprintf(
        'mysqldump -Q --opt -B %s -u %s -p%s -h %s > "%s"',
        $config['MYSQL_DATABASE'],
        $config['MYSQL_USER'],
        $config['MYSQL_PASSWORD'],
        $config['MYSQL_HOST'],
        $dumpFilePath
      )
    );
    $dump = fopen($dumpFilePath, 'rb');
    $gzFilename = $dumpFilePath . '.gz';
    $gz = gzopen($gzFilename, 'wb');
    $length = 1024 * 1024;
    while (!feof($dump)) {
      gzwrite($gz, fread($dump, $length));
    }
    fclose($dump);
    unlink($dumpFilePath);
    gzclose($gz);

    return [0, 'database backup OK'];
  }

  public function dbUpdate(): array
  {
    if (!defined('config')) {
      require self::ROOTD() . 'config/config.php';
    }

    $sql_init_files = glob(MGRT_instance_d . 'sql/init-*.sql');
    // keep natural version sort ordering, e.g. '0.0.2' come before '0.0.20'
    natsort($sql_init_files);
    $sql_init_files = array_values($sql_init_files);

    foreach ($sql_init_files as $sql_file) {
      exec(
        sprintf(
          'mysql %s -h %s -u %s -p%s 2>&1 < "%s"',
          $config['MYSQL_DATABASE'],
          $config['MYSQL_HOST'],
          $config['MYSQL_USER'],
          $config['MYSQL_PASSWORD'],
          $sql_file
        ),
        $output,
        $errno
      );
    }

    return [$errno, implode("\n", $output)];
  }

  public function cleanup()
  {
    if (!defined('config')) {
      require self::ROOTD() . 'config/config.php';
    }
    // remove instance working folder
    rmrf(MGRT_instance_d);
    // rollback database
    $rollback_file = self::WORKD() . 'rollback.sql';
    exec(
      sprintf(
        'mysql %s -h %s -u %s -p%s 2>&1 < "%s"',
        $config['MYSQL_DATABASE'],
        $config['MYSQL_HOST'],
        $config['MYSQL_USER'],
        $config['MYSQL_PASSWORD'],
        $rollback_file
      ),
      $output,
      $errno
    );

    return [$errno, implode("\n", $output)];
  }

  private function current_app_filters($key, $current, $iterator)
  {
    if ($iterator->hasChildren()) {
      return true;
    }
    // filtering images files
    if (preg_match('!^' . self::ROOTD() . '(images|caches|maps)/.*\.jpe?g$!', $current)) {
      return false;
    }
    // filtering working folder
    if (str_starts_with($current, self::WORKD())) {
      return false;
    }
    return true;
  }

  private function simple_copy($files_prefix_d, $filename, $destination)
  {
    copy($files_prefix_d . $filename, $destination . $filename);
  }

  private function backup_copy($files_prefix_d, $filename, $destination, $backup_d)
  {
    // doing backup
    copy($destination . $filename, $backup_d . $filename);
    // install file
    $this->simple_copy($files_prefix_d, $filename, $destination);
  }

  private function simple_mkdir($destination, $folder)
  {
    mkdir($destination . $folder, 0775, true);
  }

  private function backup_mkdir($destination, $folder, $backup_d)
  {
    mkdir($backup_d . $folder, 0755, true);
    $this->simple_mkdir($destination, $folder);
  }

  /**
   */
  private function copy_w_mkdir($files_tree, $destination, $files_prefix_d = null, $backup_d = false)
  {
    if (!str_ends_with($destination, '/')) {
      $destination = $destination . '/';
    }

    // get folder only
    $folders = [];
    foreach ($files_tree as $filepath) {
      $folder = dirname($filepath);
      if ($folder === '.') {
        continue;
      }
      $folders[] = $folder;
    };

    // remove dupes
    $folders = array_unique($folders);

    // Sort elements by string length (ascending)
    usort($folders, function ($a, $b) {
      if (strlen($a) > strlen($b)) {
        return 1;
      }
      if (strlen($a) < strlen($b)) {
        return -1;
      }
      // equal
      return 0;
    });

    /**
     * return only deepest path. ex:
     * @param array $files_tree array containing file path
     *
     * 1: /path1/other/something/
     * 2: /path1/other/
     * 3: /path2/something/else/
     * 4: /path2/something/
     * 5: /path3/
     *
     * 2 is already contains into 1
     * 4 is already contains into 3
     *
     * Function returns:
     * ['/path1/other/something/', '/path2/something/else/', '/path3/']
    */
    $remaining = $folders;
    for ($i = 0; $i < count($folders); $i++) {
      $withoutCurrent = $remaining;
      unset($withoutCurrent[$i]);
      foreach ($withoutCurrent as $entry) {
        // don't keep path starting with same path
        if (str_starts_with($entry, $folders[$i])) {
          unset($remaining[$i]);
          break;
        }
      }
    }
    // Re-index array keys
    array_values($remaining);

    $copy = 'simple_copy';
    $mkdir = 'simple_mkdir';
    if ($backup_d) {
      $copy = 'backup_copy';
      $mkdir = 'backup_mkdir';
    }

    // create folder tree
    set_error_handler(function () {}, E_WARNING);
    foreach ($remaining as $folder) {
      call_user_func([$this, $mkdir], $destination, $folder, $backup_d);
    }
    restore_error_handler();

    // copy files to $destination
    foreach ($files_tree as $filename) {
      call_user_func([$this, $copy], $files_prefix_d, $filename, $destination, $backup_d);
    }
  }
}
