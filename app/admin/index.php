<?php
/*
Copyright (C) 2019 Velocité Montpellier

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
require_once('../includes/security.php');

vigilo_session_start();
vigilo_admin_headers();

if(!isset($_SESSION['login']) || !isset($_SESSION['role'])) {
  header('Location: login.php');
  exit;
}

require_once('../includes/common.php');
require_once('../includes/functions.php');
require_once('../includes/handle.php');
require_once('../includes/webhooks.php');

$menu = array("dashboard" => array("icon" => "speedometer2", "name" => "Accueil", "access" => array('admin','citystaff')),
        "observations" => array("icon" => "camera", "name" => "Observations", "access" => array('admin','citystaff')),
        "resolutions" => array("icon" => "check2-square", "name" => "Résolutions", "access" => array('admin','citystaff')),
        "reports" => array("icon" => "file-earmark-bar-graph", "name" => "Rapports", "access" => array('admin','citystaff')),
        "cities" => array("icon" => "buildings", "name" => "Villes", "access" => array('admin')),
        "accounts" => array("icon" => "people", "name" => "Comptes", "access" => array('admin')),
        "scopes" => array("icon" => "compass", "name" => "Scopes", "access" => array('admin')),
        "categories" => array("icon" => "tags", "name" => "Catégories", "access" => array('admin')),
        "settings" => array("icon" => "gear", "name" => "Configuration", "access" => array('admin')),
        "webhooks" => array("icon" => "broadcast", "name" => "Webhooks", "access" => array('admin')),
        "audit" => array("icon" => "journal-text", "name" => "Journal", "access" => array('admin')),
        "update" => array("icon" => "arrow-repeat", "name" => "Mises à jour", "access" => array('admin')));

if(!isset($_GET['page']) || !array_key_exists($_GET['page'],$menu)) {
  $page_name = "dashboard";
}
else {
  $page_name = $_GET['page'];
}

/* Every page is restricted to the roles listed in its menu entry */
$page_allowed = in_array($_SESSION['role'], $menu[$page_name]['access'], true);

/* Every change (POST form or action link) must carry the CSRF token of the session */
$csrf_ok = csrf_protect_request();

/* Check config */
$config_query = mysqli_query($db,"SELECT * FROM obs_config WHERE config_param='vigilo_urlbase' LIMIT 1");
$config_result = mysqli_fetch_array($config_query);
if(empty($config_result['config_value'])) {
  $menu['settings']['confneeded'] = 1;
}

/* Check scopes */
$scopes_query = mysqli_query($db,"SELECT count(*) FROM obs_scopes");
$scopes_nb = mysqli_fetch_array($scopes_query)[0];

if($scopes_nb == 0) {
  $menu['scopes']['confneeded'] = 2;
}

/* Check Cities */
$cities_query = mysqli_query($db,"SELECT count(*) FROM obs_cities");
$cities_nb = mysqli_fetch_array($cities_query)[0];

if($cities_nb == 0) {
  $menu['cities']['confneeded'] = 3;
}

/* Pending moderation, shown in the menu */
$pending_query = mysqli_query($db, "SELECT count(*) FROM obs_list WHERE obs_complete=1 AND obs_approved=0");
$pending_nb    = $pending_query ? mysqli_fetch_array($pending_query)[0] : 0;

$instance_name = isset($config['VIGILO_NAME']) && $config['VIGILO_NAME'] !== '' ? $config['VIGILO_NAME'] : 'Vigilo';
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($menu[$page_name]['name']) ?> · <?= h($instance_name) ?> admin</title>
    <link rel="icon" href="vigilo.png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css?v=<?= intval(@filemtime(__DIR__ . '/assets/admin.css')) ?>" rel="stylesheet">
    <script>
      // Dark mode follows the system unless chosen in the menu
      (function () {
        var stored = null;
        try { stored = localStorage.getItem('vigilo-admin-theme'); } catch (e) {}
        var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
      })();
    </script>
  </head>

  <body>
    <header class="navbar sticky-top bg-dark flex-md-nowrap p-0 shadow" data-bs-theme="dark">
      <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fs-6 d-flex align-items-center gap-2" href="index.php">
        <img src="vigilo.png" alt="" width="24" height="24"> <?= h($instance_name) ?> <span class="text-white-50">admin</span>
      </a>
      <ul class="navbar-nav flex-row d-md-none">
        <li class="nav-item text-nowrap">
          <button class="nav-link px-3 text-white" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-label="Menu">
            <i class="bi bi-list fs-4"></i>
          </button>
        </li>
      </ul>
    </header>

    <div class="container-fluid">
      <div class="row">
        <div class="sidebar border-end col-md-3 col-lg-2 p-0 bg-body-tertiary">
          <div class="offcanvas-md offcanvas-end bg-body-tertiary" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
            <div class="offcanvas-header">
              <h5 class="offcanvas-title" id="sidebarMenuLabel"><?= h($instance_name) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Fermer"></button>
            </div>
            <div class="offcanvas-body d-md-flex flex-column p-0 pt-lg-3 overflow-y-auto">
              <ul class="nav flex-column">
              <?php
                foreach($menu as $key => $item) {
                  if (!in_array($_SESSION['role'], $item['access'], true)) {
                    continue;
                  }
                  $active = ($page_name == $key) ? ' active' : '';
                  echo '<li class="nav-item">';
                  echo '<a class="nav-link d-flex align-items-center gap-2' . $active . '"' . ($active ? ' aria-current="page"' : '') . ' href="?page=' . h($key) . '">';
                  echo '<i class="bi bi-' . h($item['icon']) . '"></i> ' . h($item['name']);
                  if ($key == 'observations' && $pending_nb > 0) {
                    echo ' <span class="badge rounded-pill text-bg-warning ms-auto" title="En attente de modération">' . intval($pending_nb) . '</span>';
                  }
                  if (isset($item['confneeded'])) {
                    echo ' <span class="badge rounded-pill text-bg-info ms-auto">Étape ' . intval($item['confneeded']) . '</span>';
                  }
                  echo '</a></li>';
                }
              ?>
              </ul>

              <hr class="my-3">

              <ul class="nav flex-column mb-auto">
                <li class="nav-item">
                  <span class="nav-link d-flex align-items-center gap-2 text-body-secondary">
                    <i class="bi bi-person-circle"></i> <?= h($_SESSION['login']) ?> (<?= h($_SESSION['role']) ?>)
                  </span>
                </li>
                <li class="nav-item">
                  <button type="button" class="nav-link d-flex align-items-center gap-2 btn btn-link text-start" id="theme-toggle">
                    <i class="bi bi-circle-half"></i> Thème clair / sombre
                  </button>
                </li>
                <li class="nav-item">
                  <form method="POST" action="logout.php" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="nav-link d-flex align-items-center gap-2 btn btn-link text-start">
                      <i class="bi bi-box-arrow-right"></i> Se déconnecter
                    </button>
                  </form>
                </li>
              </ul>
              <p class="small text-body-secondary px-3 mt-3">Vigilo <?= h(BACKEND_VERSION) ?></p>
            </div>
          </div>
        </div>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pb-5">
          <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><?= h($menu[$page_name]['name']) ?></h1>
          </div>

    <?php
    if (!$csrf_ok) {
        echo '<div class="alert alert-warning" role="alert"><i class="bi bi-shield-exclamation"></i> Action ignorée : jeton de sécurité absent ou expiré. Merci de recommencer depuis cette page.</div>';
    }
    if ($page_allowed) {
        include('inc/'.$page_name.'.php');
    } else {
        echo '<div class="alert alert-danger" role="alert">Accès non autorisé pour ce rôle</div>';
    }
    ?>
        </main>
      </div>
    </div>

    <!-- Photos open in this window: links with data-photo (assets/admin.js) -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalTitle" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h6" id="photoModalTitle">Photo</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
          </div>
          <div class="modal-body text-center p-2">
            <img src="" alt="" class="img-fluid rounded" id="photoModalImage" style="max-height: 80vh;">
          </div>
        </div>
      </div>
    </div>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/admin.js?v=<?= intval(@filemtime(__DIR__ . '/assets/admin.js')) ?>"></script>
  </body>
</html>
