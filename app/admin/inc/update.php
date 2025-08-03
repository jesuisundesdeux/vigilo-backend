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
require_once $_SERVER['DOCUMENT_ROOT'] . 'includes/common.php';
require_once APPD . 'includes/functions.php';
require APPD . 'admin/inc/class/autoloader.php';
use Migration\Plan;
use Migration\Migration;

// TODO: get maintenance from configuration
$maintenance = false;

/**
 * Do migration if request was a POST method, session contains a
 * 'upgradeToken' and corresponding token from POST request ;
 * else returning update page
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
  session_start();
  if (!isAdmin()
      || !isset($_SESSION['upgradeToken'])
      || $_POST['token'] !== $_SESSION['upgradeToken']
  ) {
    httpError(401);
  }

  // $plan = new FakeMigrationPlan('UP', $maintenance);
  $plan = new Plan('UP', $maintenance);
  $migration = new Migration($plan);
  $migration->migrate();
  // nothing more
  exit(0);
}

/**
 * Disalow direct page calls.
 */
if (!isAllowed()) {
  httpError(401, 'Resource not allowed ; request aborted.');
}

if (isSASS()) {
  echo '<div class="alert alert-warning" role="alert">La configuration n\'est pas accessible en SaaS</div>';
  return 1;
}

/**
 * Page was included from index.php
 */
// pick database schema version
$query_version = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version' LIMIT 1;");
$result_version = mysqli_fetch_array($query_version);
$db_version = $result_version['config_value'];

// display current versions
$badgeClass = 'badge text-monospace align-text-top px-2 py-1';
?>
<h2>Version actuelle&nbsp;:</h2>
<ul id="versions" class="list-unstyled lead">
  <li>Code&nbsp;: <span class="<?= $badgeClass ?> alert-warning"><?= BACKEND_VERSION ?></span></li>
  <li>Base de données&nbsp;: <span class="<?= $badgeClass ?> alert-dark"><?= $db_version ?></span></li>
</ul>
<?php

// check if source code and database version are coherent
if (BACKEND_VERSION != $db_version) {
  ?>
  <div class="alert alert-danger" role="alert">
    <p><strong><i class="mr-1" data-feather="alert-octagon"></i>Alerte !</strong></p>
    <p>Les versions de la base de données et du code de l'application sont différentes.</p>
    <p class="lead"><i>Cette incohérence doit être corrigée afin d'accéder au processus de mise à jour.<i></p>
  </div>
  <?php
  // stop immediately
  return 1;
}

// retrieve last remote application release
$last_version = '__UNKNOWN__';
$data = getWebContent(Plan::getReleaseUrl('vigilo-backend-latest.json'));
$github_json = json_decode($data, true);
$tag_name = $github_json['tag_name'];
if (preg_match('/^v((\d+\.){2}\d+)$/', $tag_name)) {
  $last_version = str_replace('v', '', $tag_name);
}

// check for application update
if (BACKEND_VERSION != $last_version) {
  // if not already set by an already running migration
  // TODO: flag into db (more atomic)
  if (!isset($_SESSION['migration'])) {
    $_SESSION['migration'] = 'available';
  }
  // generate migration token
  $token = tokenGenerator(16);
  $_SESSION['upgradeToken'] = $token;
  ?>
  <div class="alert alert-info fit-content" role="alert">
    <p><strong><i class="mr-1" data-feather="zap"></i>Nouvelle version disponible !</strong></p>
    <p>La version <?= $last_version ?> est disponible ; utiliser le bouton ci-dessous pour mettre à jour l'application.</p>
    <button id="upgrade" type="button" data-token="<?= $token ?>" class="btn btn-primary mt-1" type="button">Mettre à jour
      <span id="upgrade-spinner" class="spinner-grow spinner-grow-sm ml-3" role="status" aria-hidden="true" hidden></span>
    </button>
    <div id="upgradable" hidden>
      <div class="progress mt-4">
        <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
      </div>
      <hr />
      <p class="progress-what"></p>
    </div>
  </div>
  <?php
  // Migration plan steps become from PHP, create a global JS variable Map from
  // a JSON representation.
  printf('<script>const plan=new Map(%s)</script>', json_encode(Plan::getSteps('UP', $maintenance, true)));
  echo '<script src="/admin/js/migration.js" defer></script>';
}
