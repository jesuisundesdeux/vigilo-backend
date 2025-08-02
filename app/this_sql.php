<?php
// start document
echo "<pre>";
require_once('includes/common.php');

$query = "SELECT * FROM obs_config WHERE config_param='vigilo_db_version';";

$sqlQuery = mysqli_query($db, $query);
$result = mysqli_fetch_array($sqlQuery);

$query = "SELECT config_param FROM obs_config WHERE config_param='sgblur_url';";
$sqlQuery = mysqli_query($db, $query);
$result = mysqli_fetch_array($sqlQuery);
if ($result[0] === 'sgblur_url') {
    echo "update OK\n";
} else {
    $query = "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('sgblur_url','');";
    mysqli_query($db, $query);
    $query = "UPDATE `obs_config` SET `config_value` = '0.0.21' WHERE `obs_config`.`config_param` = 'vigilo_db_version';";
    $sqlQuery = mysqli_query($db, $query);
}

// echo $result[0] . " " . $result[1] . " " . $result[2] . " " . "\n";




// end of document
echo '</pre>';
