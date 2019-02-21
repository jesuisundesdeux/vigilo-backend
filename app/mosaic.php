<!-- https://codepen.io/desandro/full/RPKgEN -->
<html>
<head>
 <title>JeSuisUnDesDeux / Vigilo</title>
 <meta charset="UTF-8">

      <style>
      * { box-sizing: border-box; }

/* force scrollbar */
html { overflow-y: scroll; }

body { font-family: sans-serif; }

/* ---- grid ---- */

.grid {
  background: #DDD;
}

/* clear fix */
.grid:after {
  content: '';
  display: block;
  clear: both;
}

/* ---- .grid-item ---- */

.grid-sizer,
.grid-item {
  width: 25%;
}

.grid-item {
  float: left;
}

.grid-item img {
  display: block;
  max-width: 100%;
}

    </style>

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


if(isset($_GET['c']) AND !empty($_GET['g'])) {
  $cat = $_GET['c'] ;
}
else {
  $cat = 'all';
}

if(isset($_GET['t']) AND !empty($_GET['t'])) {
  $token = $_GET['t'] ;
}
else {
  $token = 'all';
}

$url = 'https://vigilo.jesuisundesdeux.org/get_issues.php';

$data = file_get_contents($url);

$content = json_decode($data,true);
$item= 0;
$filter = array('distance' => 200,'fdistance' => 1, 'fcategorie' => 1,'faddress' => 1)
foreach($content as $value) {
  if(($value['categorie'] == $cat OR $cat == 'all') AND (in_array($token,sameas($db,$token,$filter))) OR $token = 'all') {
    echo '<div class="grid-item"><a target="_blank" href="https://umap.openstreetmap.fr/en/map/vigilo_286846#19/'.$value['coordinates_lat'].'/'.$value['coordinates_lon'].'"><img width="100%" src="https://vigilo.jesuisundesdeux.org/generate_panel.php?token='.$value['token'].'&s=400" /></a></div>';
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


