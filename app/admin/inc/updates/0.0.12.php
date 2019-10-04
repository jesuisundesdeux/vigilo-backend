<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}
$from = "0.0.12";
$to = "0.0.13";

$sql[] = 'ALTER TABLE `obs_list` ADD `obs_cityname` VARCHAR(255) NOT NULL AFTER `obs_city`;';
$sql[] = 'ALTER TABLE `obs_roles` ADD `role_city` VARCHAR(255) NOT NULL AFTER `role_password`;';
$sql[] = 'INSERT INTO obs_config (config_param,config_value) VALUES ("migration_flag",1);';

$config_query = mysqli_query($db, "SELECT config_value FROM obs_config WHERE config_param='migration_flag' LIMIT 1");
$config_result = mysqli_fetch_array($config_query);
?>
<h3><?=$from ?> à <?=$to ?></h3>

<?php
if(!isset($config_result['config_value']) || $config_result['config_value'] == 0) {
?>
  <form action="?page=update" method="POST">
    <input type="hidden" name="update" value="1" />
    <button class="btn btn-primary" type="submit">Mettre à jour la base</button>
  </form>
  <br />
  <?php
  
  if(isset($_POST['update'])) {
    $errors = array();
    mysqli_begin_transaction($db);
    foreach($sql as $query) {
      mysqli_query($db,$query);
      if(mysqli_error($db)) {
        $errors[] = mysqli_error($db);
        mysqli_rollback($db);
        break;
      }
    }
    mysqli_commit($db);
  
    if(count($errors) != 0) {
    ?>
    <div class="alert alert-danger" role="alert">
      <strong>Erreur lors de la mise à jour de la base de données </strong><br />
      <?php foreach($errors as $error) {
      echo $error . '<br />';
      }
      ?>
    </div>
    <?php
    }
    else {
    ?>
    <div class="alert alert-info" role="alert">
      Mis à jour effectuée sur la base, cliquer <a href='?page=update'>ici</a> pour continuer
    </div>
    <?php
    }
  }
  
}
elseif(isset($config_result['config_value']) || $config_result['config_value'] == 1) {
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

