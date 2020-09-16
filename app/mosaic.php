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

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");
require_once("${cwd}/includes/functions.php");

?>
<!-- https://codepen.io/desandro/full/RPKgEN -->
<!DOCTYPE html>
<?php
echo '<html lang="' . $config['VIGILO_LANGUAGE'] . '">';
?>
<head>
  <?php
echo '<title>' . $config['VIGILO_NAME'] . '</title>';
?>
 <meta http-equiv="Content-type" content="text/html; charset=utf-8">
  <link href="/style/mosaic.css" type="text/css" rel="stylesheet">
  <link rel="icon" type="image/png" href="/style/favicon.png">

  <script>
  window.console = window.console || function(t) {};
  </script>

  <script>
  if (document.location.search.match(/type=embed/gi)) {
    window.parent.postMessage("resize", "*");
  }
  </script>
</head>

<body bgcolor="#000000">
  <div class="grid">
  <div class="grid-sizer"></div>

<?php

if (isset($_GET['c']) AND !empty($_GET['c'])) {
    $cat = $_GET['c'];
} else {
    $cat = 'all';
}

if (isset($_GET['t']) AND !empty($_GET['t'])) {
    $token = $_GET['t'];
} else {
    $token = 'all';
}

$obslink = 'image';
if (isset($_GET['scope']) AND !empty($_GET['scope'])) {
    $scope = $_GET['scope'];
    if ($instance_name = getInstanceNameFromFirebase($scope)) {
        $obslink = 'web';
    }
} else {
    $scope = '';
}

$url = $config['HTTP_PROTOCOL'] . '://' . $config['URLBASE'] . '/get_issues.php';

$data    = file_get_contents($url);
/*
 *  TODO
 *  We should use get_issues filters instead of filtering the whole list
 *  each time we call mosaic.php
 */
$content = json_decode($data, true);
$item    = 0;
$filter  = array(
    'distance' => 300,
    'fdistance' => 1,
    'fcategorie' => 1,
    'faddress' => 1
);
$similar = sameas($db, $token, $filter);

foreach ($content as $value) {
    if ($cat != 'all' && $value['categorie'] != $cat) {
        /* Wrong category - Do not display */
        continue;
    }
    if ($token != 'all' && (!isset($similar) OR !in_array($value['token'], $similar))) {
        /* Wrong token - Do not display */
        continue;
    }
    
    if ($obslink == 'web') {
        $obsurl = 'https://app.vigilo.city/?token=' . $value['token'] . '&instance=' . $instance_name;
    } else {
        $obsurl = '/generate_panel.php?token=' . $value['token'];
    }
    
    echo '<div class="grid-item"><a target="_blank" href="' . $obsurl . '"><img width="100%" src="' . $config['HTTP_PROTOCOL'] . '://' . $config['URLBASE'] . '/generate_panel.php?token=' . $value['token'] . '&s=400" /></a></div>';
}
?>
</div>
  <script src="https://static.codepen.io/assets/common/stopExecutionOnTimeout-de7e2ef6bfefd24b79a3f68b414b87b8db5b08439cac3f1012092b2290c719cd.js"></script>

  <script src='//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js'></script>
  <script src='https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.js'></script>
  <script src='https://unpkg.com/imagesloaded@4/imagesloaded.pkgd.js'></script>

  <script >
    // external js: masonry.pkgd.js, imagesloaded.pkgd.js

    // init Masonry
    var $grid = $('.grid').masonry({
      itemSelector: '.grid-item',
      percentPosition: true,
      columnWidth: '.grid-sizer'
    });
    // layout Masonry after each image loads
    $grid.imagesLoaded().progress( function() {
      $grid.masonry();
    });
    //# sourceURL=pen.js
  </script>

  <script src="https://static.codepen.io/assets/editor/live/css_reload-5619dc0905a68b2e6298901de54f73cefe4e079f65a75406858d92924b4938bf.js"></script>
</body>
</html>
