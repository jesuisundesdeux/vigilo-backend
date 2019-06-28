<?php

require_once('../includes/common.php');

$menu = array("dashboard" => array("icon" => "home", "name" => "Accueil"),
	      "observations" => array("icon" => "list", "name" => "Observationis"),
	      "cities" => array("icon" => "briefcase", "name" => "Villes"),
	      "accounts" => array("icon" => "users", "name" => "Comptes"),
	      "scopes" => array("icon" => "compass", "name" => "Scopes"),
	      "twitter" => array("icon" => "twitter", "name" => "Twitter"),
	      "settings" => array("icon" => "settings", "name" => "Configuration"),
	      "update" => array("icon" => "zap", "name" => "Mises à jours"));

if(!isset($_GET['page']) || !array_key_exists($_GET['page'],$menu)) {
	$page_name = "dashboard";
}
else {
	$page_name = $_GET['page'];
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
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
          <a class="nav-link" href="#">Sign out</a>
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
                if($page_name == $key) { $active = "active"; }
		else { $active = ''; }
	      echo '<li class="nav-item">';
              echo '<a class="nav-link '.$active.'" href="?page='.$key.'">';
	      echo '<span data-feather="'.$item['icon'].'"></span>';
	      echo $item['name'] ;
	      echo '</a>';
	      echo '</li>';
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

