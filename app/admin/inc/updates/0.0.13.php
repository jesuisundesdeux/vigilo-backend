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
  $citycount_query = mysqli_query($db,"SELECT * FROM obs_cities");
  $citycount = mysqli_num_rows($citycount_query);
  $citylist = array();
  while($city_result = mysqli_fetch_array($citycount_query)) {
    $citylist[] = $city_result['city_name'];
  }
  ?>
  <div class="alert alert-warning" role="alert">
    Assurez vous d'avoir completé la liste des villes avant de faire la mise à jour<br />
    <strong><?=$citycount ?></strong> villes configurées
  </div>
  
  <?php
  $obslist_query = mysqli_query($db, "SELECT obs_token,obs_address_string FROM obs_list WHERE obs_cityname='' AND obs_city=0");
  
  $tokenpb = array();
  $citiesunknown = array();
  while ($obslist_result = mysqli_fetch_array($obslist_query)) {
    $token = $obslist_result['obs_token'];
    preg_match('/^(?:[^,]*),([^,]*)$/',$obslist_result['obs_address_string'],$cityInadress);
    if (count($cityInadress) != 2) {
      $tokenpb[] = $token . ' => '. $obslist_result['obs_address_string'];
    }
    elseif (!in_array(strtolower(trim($cityInadress[1])),$citylist)) {
      $citiesunknown[] = $token . ' => ' .$cityInadress[1];
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
  if (count($citiesunknown)) {
  ?>
  <div class="alert alert-info" role="alert">
  Certaines villes renseignées ne sont pas dans la liste configurée<br /><br />
  <?php 
    foreach($citiesunknown as $value) {
      echo "$value<br />";
    } 
  ?>
  </div>

  <?php
  }
}
?>

