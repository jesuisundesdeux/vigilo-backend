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

require_once('./common.php');
require_once('./functions.php');
?>
<!-- https://codepen.io/desandro/full/RPKgEN -->
<html>
<head>
 <title>JeSuisUnDesDeux / Vigilo</title>
 <meta charset="UTF-8">
 <link href="/style/mosaic.css" type="text/css" rel="stylesheet">

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
  $cat = $_GET['c'] ;
}
else {
  $cat = 'all';
}

if (isset($_GET['t']) AND !empty($_GET['t'])) {
  $token = $_GET['t'] ;
}
else {
  $token = 'all';
}

$url = 'https://'.$urlbase.'/get_issues.php';

$data = file_get_contents($url);

$content = json_decode($data,true);
$item= 0;
$filter = array('distance' => 300,'fdistance' => 1, 'fcategorie' => 1,'faddress' => 1);
$similar = sameas($db, $token, $filter);

foreach ($content as $value) {
  if (($value['categorie'] == $cat OR $cat == 'all') AND
     ((in_array($value['token'], $similar)) OR $token == 'all')) {
    echo '<div class="grid-item"><a target="_blank" href="'.$umap_url.'/'.$value['coordinates_lat'].'/'.$value['coordinates_lon'].'"><img width="100%" src="https://'.$urlbase.'/generate_panel.php?token='.$value['token'].'&s=400" /></a></div>';
  }
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
</body></html>

