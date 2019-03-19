<?php
require_once('../common.php');
require_once('../functions.php');

$token = mysqli_real_escape_string($db, $_GET['token']);

# Get issue information
$query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_token = '$token' LIMIT 1");

if (mysqli_num_rows($query) == 1) {
    $result = mysqli_fetch_array($query);
    $approved = $result['obs_approved'];
  
  
  ## Init other images components :
  echo '<meta http-equiv="content-type" content="text/html; charset=utf-8" />';
  echo '<table cellpadding="0" cellspacing="0" class="db-table">';
  echo '<tr><th>photo</th><th>action</th></tr>';

  echo '<tr>';
  echo '<td><img src="/generate_panel.php?token='.$token.'"></img></td>';
  echo '<td>';
  echo '<a href="/admin/set_approve.php?approve=1&token='.$token.'">Approuver photo</a></br>';
  echo '<a href="/admin/set_approve.php?approve=0&token='.$token.'">Désapprouver photo</a></br>';
  echo 'STATUS:'.$approved;

  echo '</td>';
  echo '</tr>';
}
