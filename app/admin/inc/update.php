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

if (!isset($page_name) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $menu[$page_name]['access'])) {
    exit('Not allowed');
}

if (isset($config['SAAS_MODE']) && $config['SAAS_MODE']) {
    echo '<div class="alert alert-warning" role="alert">La configuration n\'est pas accessible en SaaS</div>';
    return;
}

require_once(dirname(__FILE__) . '/../../includes/updater.php');

/* Password of the logged admin, asked again before installing code */
function update_password_ok($db, $password)
{
    $query = mysqli_query($db, "SELECT role_password FROM obs_roles WHERE role_login='" . mysqli_real_escape_string($db, $_SESSION['login']) . "' AND role_name='admin' LIMIT 1");
    $row   = $query ? mysqli_fetch_array($query) : null;
    return $row && $password !== '' && password_verify($password, $row['role_password']);
}

$messages = array();
$steps    = array();
$log      = function ($message) use (&$steps) {
    $steps[] = $message;
};

$runtime = vigilo_runtime();
$release = vigilo_latest_release(isset($_POST['refresh']));

/* Actions */
if (isset($_POST['apply_migrations'])) {
    try {
        $applied = vigilo_migrate($db, BACKEND_VERSION, $log);
        audit_log('update_migrations', BACKEND_VERSION, $applied);
        $messages[] = array('success', 'Base de données à jour (' . BACKEND_VERSION . ')' . (count($applied) ? ' : migrations ' . implode(', ', $applied) . ' appliquées.' : '.'));
    } catch (Exception $e) {
        error_log('[MIGRATION] ' . $e->getMessage());
        $messages[] = array('danger', 'Migration en échec : ' . $e->getMessage());
    }
} elseif (isset($_POST['install_release'])) {
    if (!update_password_ok($db, isset($_POST['password']) ? (string) $_POST['password'] : '')) {
        $messages[] = array('danger', 'Mot de passe incorrect : mise à jour annulée.');
    } elseif (!$release || !isset($_POST['version']) || $_POST['version'] !== $release['version']) {
        $messages[] = array('warning', 'La version disponible a changé, merci de vérifier avant de relancer.');
    } else {
        try {
            $installed = vigilo_install_release($db, $release, $log);
            audit_log('update_install', $installed, array('from' => BACKEND_VERSION));
            $messages[] = array('success', "Vigilo $installed est installé. Rechargez la page.");
        } catch (Exception $e) {
            audit_log('update_failed', $release['version'], $e->getMessage());
            $messages[] = array('danger', 'Mise à jour en échec : ' . $e->getMessage());
        }
    }
} elseif (isset($_POST['watchtower_update'])) {
    try {
        vigilo_watchtower_update();
        audit_log('update_docker_watchtower', $release ? $release['version'] : '');
        $messages[] = array('success', "Watchtower télécharge la nouvelle image et redémarre le conteneur. L'administration sera indisponible une à deux minutes ; rechargez ensuite cette page.");
    } catch (Exception $e) {
        $messages[] = array('danger', $e->getMessage());
    }
}

$query_version  = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version' LIMIT 1");
$result_version = $query_version ? mysqli_fetch_array($query_version) : null;
$db_version     = $result_version ? $result_version['config_value'] : '?';
$code_version   = BACKEND_VERSION;
$image_version  = getenv('VIGILO_IMAGE_VERSION');
$last_version   = $release ? $release['version'] : null;
$new_release    = $last_version && version_compare($last_version, $code_version, '>');
$blockers       = vigilo_update_blockers($release);
$pending        = preg_match('/^\d+\.\d+\.\d+$/', $db_version) && version_compare($db_version, $code_version, '<')
    ? array_keys(vigilo_migrations_pending($db_version, $code_version)) : array();

foreach ($messages as $message) {
    echo '<div class="alert alert-' . h($message[0]) . ' alert-dismissible fade show" role="alert">' . h($message[1])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
}
if ($steps) {
    echo '<div class="card mb-4"><div class="card-header">Déroulement</div><ul class="list-group list-group-flush small">';
    foreach ($steps as $step) {
        echo '<li class="list-group-item">' . h($step) . '</li>';
    }
    echo '</ul></div>';
}
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Version du code</div>
      <div class="fs-4 fw-semibold"><?= h($code_version) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Version de la base</div>
      <div class="fs-4 fw-semibold <?= $db_version !== $code_version ? 'text-danger' : '' ?>"><?= h($db_version) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Installation</div>
      <div class="fs-5 fw-semibold"><?= $runtime == 'docker' ? '<i class="bi bi-box-seam"></i> Docker' : '<i class="bi bi-hdd-rack"></i> Serveur PHP' ?></div>
      <div class="small text-body-secondary">PHP <?= h(PHP_VERSION) ?><?= $image_version ? ' · image ' . h($image_version) : '' ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-body-secondary small">Dernière version publiée</div>
      <div class="fs-4 fw-semibold <?= $new_release ? 'text-success' : '' ?>"><?= $last_version ? h($last_version) : '<span class="fs-6 text-body-secondary">inconnue (GitHub injoignable)</span>' ?></div>
      <form method="POST" class="m-0"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0" name="refresh" value="1"><i class="bi bi-arrow-clockwise"></i> Vérifier maintenant</button></form>
    </div></div>
  </div>
</div>

<?php if ($db_version !== $code_version && preg_match('/^\d+\.\d+\.\d+$/', $db_version) && version_compare($db_version, $code_version, '<')) { ?>
<div class="card border-warning mb-4">
  <div class="card-header text-bg-warning"><i class="bi bi-database-exclamation"></i> La base de données doit être mise à jour</div>
  <div class="card-body">
    <p>Migrations à appliquer : <strong><?= count($pending) ? h(implode(', ', $pending)) : 'aucune (mise à jour du numéro de version)' ?></strong>.</p>
    <form method="POST" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" name="apply_migrations" value="1" class="btn btn-warning" data-confirm="Appliquer les migrations de la base de données ? Pensez à faire une sauvegarde."><i class="bi bi-play-fill"></i> Appliquer les migrations</button>
    </form>
  </div>
</div>
<?php } elseif ($db_version !== $code_version) { ?>
<div class="alert alert-danger"><strong>Attention :</strong> la base (<?= h($db_version) ?>) est plus récente que le code (<?= h($code_version) ?>). Réinstallez la version <?= h($db_version) ?> du code.</div>
<?php } ?>

<?php if ($new_release) { ?>
<div class="card border-success mb-4">
  <div class="card-header text-bg-success"><i class="bi bi-stars"></i> Vigilo <?= h($last_version) ?> est disponible</div>
  <div class="card-body">
    <?php if (!empty($release['notes'])) { ?>
      <details class="mb-3"><summary>Notes de version</summary><pre class="small mt-2 mb-0" style="white-space: pre-wrap"><?= h($release['notes']) ?></pre></details>
    <?php } ?>
    <?php if (!empty($release['url'])) { ?>
      <p><a href="<?= h($release['url']) ?>" target="_blank" rel="noopener">Voir la version sur GitHub <i class="bi bi-box-arrow-up-right"></i></a></p>
    <?php } ?>

    <?php if ($runtime == 'docker') { ?>
      <p>Sur une instance Docker, le code fait partie de l'image : la mise à jour consiste à démarrer l'image de la nouvelle version.
      La base de données est migrée automatiquement au démarrage du conteneur (sauf si <code>AUTOUPDATE=false</code>).</p>
      <?php if (vigilo_watchtower_configured()) { ?>
        <form method="POST" class="mb-3">
          <?= csrf_field() ?>
          <button type="submit" name="watchtower_update" value="1" class="btn btn-success" data-confirm="Mettre à jour l'image Docker maintenant ? L'instance redémarre (une à deux minutes)."><i class="bi bi-cloud-download"></i> Mettre à jour l'image avec Watchtower</button>
        </form>
        <p class="small text-body-secondary">Watchtower met à jour l'image suivie par le conteneur (par exemple <code>vigilobs/vigilo-backend:0.0</code>). Pour passer à une version majeure (0.1, 1.0…), changez le tag dans <code>docker-compose.yml</code>.</p>
      <?php } else { ?>
        <p>Sur le serveur, dans le répertoire de <code>docker-compose.yml</code> :</p>
        <pre class="bg-body-tertiary p-3 rounded small mb-3">docker compose pull
docker compose up -d</pre>
        <p class="small text-body-secondary mb-0">L'image doit suivre un tag de version (<code>vigilobs/vigilo-backend:0.0</code> ou <code>:stable</code>), pas un numéro figé.
        Pour mettre à jour depuis cette page, ajoutez le service Watchtower du fichier <code>docker-compose.yml</code> fourni et définissez
        <code>VIGILO_WATCHTOWER_URL</code> et <code>VIGILO_WATCHTOWER_TOKEN</code> (voir doc/UPGRADE.md).</p>
      <?php } ?>
    <?php } elseif ($blockers) { ?>
      <div class="alert alert-secondary mb-3">
        <strong>Mise à jour automatique impossible :</strong>
        <ul class="mb-0"><?php foreach ($blockers as $blocker) { echo '<li>' . h($blocker) . '</li>'; } ?></ul>
      </div>
      <p class="mb-0">Mise à jour manuelle : téléchargez l'archive de la version sur GitHub, remplacez le contenu du répertoire <code>app</code> sur le serveur
      (sans écraser <code>config/config.php</code>, <code>images</code>, <code>caches</code> et <code>maps</code>), puis revenez sur cette page pour appliquer les migrations.</p>
    <?php } else { ?>
      <p>La mise à jour télécharge l'archive publiée sur GitHub, vérifie son intégrité<?= VIGILO_RELEASE_PUBLIC_KEY !== '' ? ' et sa signature' : '' ?>,
      sauvegarde le code et la base de données, installe les fichiers, applique les migrations puis vérifie que l'instance répond.
      En cas d'échec, la version actuelle est restaurée.</p>
      <form method="POST" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= h($last_version) ?>">
        <div class="col-sm-6 col-lg-4">
          <label for="update-password" class="form-label">Votre mot de passe (confirmation)</label>
          <input type="password" class="form-control" id="update-password" name="password" required autocomplete="current-password">
        </div>
        <div class="col-auto">
          <button type="submit" name="install_release" value="1" class="btn btn-success" data-confirm="Installer Vigilo <?= h($last_version) ?> maintenant ?"><i class="bi bi-cloud-download"></i> Installer la version <?= h($last_version) ?></button>
        </div>
      </form>
    <?php } ?>
  </div>
</div>
<?php } elseif ($last_version) { ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> Vigilo est à jour.</div>
<?php } ?>

<div class="card mb-4">
  <div class="card-header"><i class="bi bi-shield-check"></i> Vérifications de sécurité</div>
  <ul class="list-group list-group-flush">
  <?php
    $checks = vigilo_security_checks();
    if (!$checks) {
        echo '<li class="list-group-item text-body-secondary">Configurez l\'URL de l\'instance (Configuration) pour vérifier l\'accès aux photos.</li>';
    }
    $icons = array('danger' => 'x-octagon-fill', 'warning' => 'exclamation-triangle-fill', 'success' => 'check-circle-fill');
    foreach ($checks as $check) {
        echo '<li class="list-group-item"><i class="bi bi-' . h($icons[$check[0]]) . ' text-' . h($check[0]) . '"></i> <strong>' . h($check[1]) . '</strong>';
        if ($check[2] !== '') {
            echo '<div class="small text-body-secondary mt-1">' . h($check[2]) . '</div>';
        }
        echo '</li>';
    }
  ?>
  </ul>
</div>

<?php
$migration_query       = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='migration_from_version' LIMIT 1");
$migration_result      = $migration_query ? mysqli_fetch_array($migration_query) : null;
$migration_fromversion = $migration_result ? basename($migration_result['config_value']) : '';

// Post-migration operations of old versions
if ($migration_fromversion !== '' && file_exists('./inc/updates/' . $migration_fromversion . '.php')) {
    echo "<h2>Migration nécessaire</h2>";
    require_once('./inc/updates/' . $migration_fromversion . '.php');
}
