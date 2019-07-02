<?php
$obs_query = mysqli_query($db,"SELECT * FROM obs_list");
$count_total = 0;
$count_complete = 0;
$count_approved = 0;
$count_disapprove = 0;
$count_waitingapprove = 0;

while($obs_result = mysqli_fetch_array($obs_query)) {
  if($obs_result['obs_complete'] == 1) {
    $count_complete++;
    if($obs_result['obs_approved'] == 1) {
      $count_approved++;
    }
    elseif($obs_result['obs_approved'] == 2) {
      $count_disapprove++;
    }
    else {
      $count_waitingapprove++;
    }
  }
  $count_total++;
} 
?>

<div class="jumbotron jumbotron-fluid">
  <div class="container">
    <h1 class="display-3">Quelques stats</h1>
    <p class="lead">
      <strong><?=$count_total ?></strong> observations totales<br />
      <strong><?=$count_complete ?></strong> observations complètes<br />
      <strong><?=$count_approved ?></strong> observations approuvées<br />
      <strong><?=$count_disapprove ?></strong> observations désapprouvées<br />
      <strong><?=$count_waitingapprove ?></strong> observations en attente d'approbation
    </p>
  </div>
</div>
