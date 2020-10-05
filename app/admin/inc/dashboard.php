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

$obs_query            = mysqli_query($db, "SELECT * FROM obs_list");
$count_total          = 0;
$count_complete       = 0;
$count_approved       = 0;
$count_disapprove     = 0;
$count_waitingapprove = 0;

while ($obs_result = mysqli_fetch_array($obs_query)) {
    if ($obs_result['obs_complete'] == 1) {
        $count_complete++;
        if ($obs_result['obs_approved'] == 1) {
            $count_approved++;
        } elseif ($obs_result['obs_approved'] == 2) {
            $count_disapprove++;
        } else {
            $count_waitingapprove++;
        }
    }
    $count_total++;
}


if (file_exists('../install.php')) {
?>
<div class="alert alert-danger" role="alert">
  <strong>Installation !</strong> Veuillez supprimer le fichier ./install.php
</div>
<?php
}
?>


<div class="jumbotron jumbotron-fluid">
  <div class="container">
    <h1 class="display-3">Quelques stats</h1>
    <p class="lead">
      <strong><?= $count_total ?></strong> observations totales<br />
      <strong><?= $count_complete ?></strong> observations complètes<br />
      <strong><?= $count_approved ?></strong> observations approuvées<br />
      <strong><?= $count_disapprove ?></strong> observations désapprouvées<br />
      <strong><?= $count_waitingapprove ?></strong> observations en attente d'approbation
    </p>
  </div>
</div>
