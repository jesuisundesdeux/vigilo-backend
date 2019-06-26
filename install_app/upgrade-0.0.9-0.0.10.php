<?php

echo "<p>Ce fichier doit être mis à la racine de app/ et supprimé une fois executé</p>";

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");

$query_version = mysqli_query($db, "SELECT * FROM obs_config WHERE config_param='vigilo_db_version'");
$query_result = mysqli_fetch_array($query_version);

echo "<p>Version actuelle code: " . BACKEND_VERSION . '</p>';
echo "<p>Version de la base: " . $query_result['config_value'] . '</p>';

if ($query_result['config_value'] == "0.0.10") {

  $query_obs = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_status !=0");
  while($result_obs = mysqli_fetch_array($query_obs)) {
    $obsid = $result_obs['obs_id'];
    mysqli_query($db, "INSERT INTO obs_status_update (status_update_obsid,status_update_status,status_update_time) VALUES ('$obsid','1','".time()."')");
  }
}
else {
  echo "Base de données non mises à jour, version actuelle => " . $query_result['config_value'];
}

