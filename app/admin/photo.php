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
