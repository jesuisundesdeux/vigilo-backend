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
session_start();

if(!isset($_SESSION['login'])) {
  header('Location: login.php');
}

require_once('../includes/common.php');
require_once('../includes/functions.php');
require_once('../includes/handle.php');

$menu = array("dashboard" => array("icon" => "home", "name" => "Accueil", "access" => array('admin','citystaff')),
        "observations" => array("icon" => "list", "name" => "Observations", "access" => array('admin','citystaff')),
        "resolutions" => array("icon" => "user-check", "name" => "Resolutions", "access" => array('admin','citystaff')),
        "cities" => array("icon" => "briefcase", "name" => "Villes", "access" => array('admin')),
        "accounts" => array("icon" => "users", "name" => "Comptes", "access" => array('admin')),
        "scopes" => array("icon" => "compass", "name" => "Scopes", "access" => array('admin')),
        "twitter" => array("icon" => "twitter", "name" => "Twitter", "access" => array('admin')),
        "settings" => array("icon" => "settings", "name" => "Configuration", "access" => array('admin')),
        "update" => array("icon" => "zap", "name" => "Mises à jour", "access" => array('admin')));

if(!isset($_GET['page']) || !array_key_exists($_GET['page'],$menu)) {
  $page_name = "dashboard";
}
else {
  $page_name = $_GET['page'];
}

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

?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Vigilo - <?= $menu[$page_name]['name'] ?></title>

    <link rel="canonical" href="https://getbootstrap.com/docs/4.0/examples/dashboard/">

    <!-- Bootstrap core CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/dashboard.css" rel="stylesheet">
  </head>

  <body>
    <nav class="navbar navbar-dark sticky-top bg-dark flex-md-nowrap p-0">
      <a class="navbar-brand col-sm-3 col-md-2 mr-0" href="#">Vigilo Admin</a>
      <ul class="navbar-nav px-3">
        <li class="nav-item text-nowrap">
          <a class="nav-link" href="login.php">Sign out</a>
        </li>
      </ul>
    </nav>

    <div class="container-fluid">
      <div class="row">
        <nav class="col-md-2 d-none d-md-block bg-light sidebar">
          <div class="sidebar-sticky">
            <ul class="nav flex-column">
            <?php
              foreach($menu as $key => $item) {
                if($page_name == $key) {
                  $active = "active";
                }
                else {
                  $active = '';
                }

                if (in_array($_SESSION['role'],$item['access'])) {
                    echo '<li class="nav-item">';
                    echo '<a class="nav-link '.$active.'" href="?page='.$key.'">';
                      echo '<span data-feather="'.$item['icon'].'"></span>';
                        echo $item['name'] ;
                        if(isset($item['confneeded'])) {
                            echo ' <span class="badge badge-pill badge-info">Configuration / étape '.$item['confneeded'].'</span>';
                        }
                      echo '</a>';
              echo '</li>';
                }
             } ?>
            </ul>

          </div>
        </nav>

        <main role="main" class="col-md-9 ml-sm-auto col-lg-10 pt-3 px-4">
          <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2"><?= $menu[$page_name]['name'] ?></h1>
          </div>

    <?php include('inc/'.$page_name.'.php'); ?>
        </main>
      </div>
    </div>

    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script>window.jQuery || document.write('<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"><\/script>')</script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

    <!-- Icons -->
    <script src="https://unpkg.com/feather-icons/dist/feather.min.js"></script>
    <script>
      feather.replace()
    </script>

  </body>
</html>
