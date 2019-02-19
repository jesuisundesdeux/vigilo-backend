<html>
<head />
<body bgcolor="#000000">
<div align="center">
<?php


if(isset($_GET['c'])) {
  $cat = $_GET['c'] ;
}
else {
  $cat = 'all';
}
$url = 'https://vigilo.jesuisundesdeux.org/get_issues.php';

$data = file_get_contents($url);

$content = json_decode($data,true);
$item= 0;
foreach($content as $value) {
if($value['categorie'] == $cat OR $cat == 'all') {
  $item++;
  echo '<a target="_blank" href="https://umap.openstreetmap.fr/en/map/vigilo_286846#19/'.$value['coordinates_lat'].'/'.$value['coordinates_lon'].'"><img width=20%" src="https://vigilo.jesuisundesdeux.org/generate_panel.php?token='.$value['token'].'&s=400" /></a>';
  if($item == 5) {
    $item = 0;
    echo "<br />";
  }
}
}
?>
</div>
</body>
