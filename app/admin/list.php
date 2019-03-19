<?php
require_once('../common.php');
require_once('../functions.php');
$sresample=200;

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list ORDER BY obs_time DESC");

if (mysqli_num_rows($query) > 0) {
  echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
  echo '<table cellpadding="0" cellspacing="0" class="db-table">';
  echo '<tr><th>time</th><th>street_name</th><th>comment</th><th>categorie<th>photo</th><th>approved</th><th>delete</th></tr>';
  while ($result = mysqli_fetch_array($query)) {
    $coordinates_lat = $result['obs_coordinates_lat'];
    $coordinates_lon = $result['obs_coordinates_lon'];
    $street_name = $result['obs_address_string'];
    $comment = $result['obs_comment'];
    $categorie = $result['obs_categorie'];
    $token = $result['obs_token'];
    $time = $result['obs_time'];
    $status = $result['obs_status'];
    $version = $result['obs_app_version'];
    $approved = $result['obs_approved'];
    $secretid = $result['obs_secretid'];

    echo '<tr>';
    echo '<td>'.$time.'</td>';
    echo '<td>'.$street_name.'</td>';
    echo '<td>'.$comment.'</td>';
    echo '<td>'.$categorie.'</td>';
    echo '<td><a href="/admin/photo.php?token='.$token.'" target="_blank"><img src="/generate_panel.php?s='.$sresample.'&token='.$token.'"</img><a/></td>';
    echo '<td>'.$approved.'</td>';
    echo '<td><a href="/delete.php?token='.$token.'&secretid='.$secretid.'">Supprimer</a></td>';
    echo "</tr>\n";
  }
  echo '</table><br />';
}
