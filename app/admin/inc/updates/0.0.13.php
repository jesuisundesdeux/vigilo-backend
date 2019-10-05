<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}
$from = "0.0.12";
$to = "0.0.13";

$config_query = mysqli_query($db, "SELECT config_param,config_value FROM obs_config");
while($config_result = mysqli_fetch_array($config_query)) {
  if($config_result['config_param'] == 'vigilo_db_version') {
    $dbversion = $config_result['config_value'];
  }
  elseif($config_result['config_param'] == 'migration_flag') {
    $migration_flag = $config_result['config_value'];
  }
}
?>
<h3><?=$from ?> à <?=$to ?></h3>
<?php
if ($dbversion == "0.0.13" && $migration_flag == "1") {
  $citycount_query = mysqli_query($db,"SELECT count(*) as nb FROM obs_cities");
  $citycount_result = mysqli_fetch_array($citycount_query);
  ?>
  <div class="alert alert-warning" role="alert">
    Assurez vous d'avoir completé la liste des villes avant de faire la mise à jour<br />
    <strong><?=$citycount_result['nb'] ?></strong> villes configurées
  </div>
  
  <?php
  $obslist_query = mysqli_query($db, "SELECT obs_token,obs_address_string FROM obs_list");
  $tokenpb = array();
  while($obslist_result = mysqli_fetch_array($obslist_query)) {
    $token = $obslist_result['obs_token'];
    preg_match('/^(?:[^,]*),([^,]*)$/',$obslist_result['obs_address_string'],$cityInadress);
    if(count($cityInadress) != 2) {
      $tokenpb[] = $token . ' => '. $obslist_result['obs_address_string'];
    }
  }
  
  if(count($tokenpb)) {
  ?>
  <div class="alert alert-danger" role="alert">
    Les adresses des observations suivantes ne sont pas au format "Rue, Ville", il est necessaire de les modifier avant de continuer<br /><br />
  <?php 
    foreach($tokenpb as $value) {
      echo "$value<br />";
    } 
  ?>
  </div>
 
<?php
  }
}
?>

