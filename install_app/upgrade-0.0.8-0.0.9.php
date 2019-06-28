<?php

echo "<p>Ce fichier doit être mis à la racine de app/ et supprimé une fois executé</p>";

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");

$query_version = mysqli_query($db, "SELECT * FROM obs_config WHERE config_param='vigilo_db_version'");
$query_result = mysqli_fetch_array($query_version);

echo "<p>Version actuelle code: " . BACKEND_VERSION . '</p>';
echo "<p>Version de la base: " . $query_result['config_value'] . '</p>';

if (BACKEND_VERSION == "0.0.8" && $query_result['config_value'] == "0.0.9") {
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_urlbase','".$config['URLBASE']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_http_proto','".$config['HTTP_PROTOCOL']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_name','".$config['VIGILO_NAME']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_language','".$config['VIGILO_LANGUAGE']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_mapquest_api','".$config['MAPQUEST_API']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('twitter_expiry_time','".$config['APPROVE_TWITTER_EXPTIME']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mysql_charset','".$config['MYSQL_CHARSET']."')");
  mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_timezone','".$config['VIGILO_TIMEZONE']."')");

  mysqli_query($db, "INSERT INTO obs_twitteraccounts (`ta_consumer`,`ta_consumersecret`,`ta_accesstoken`,`ta_accesstokensecret`)
								VALUES ('".$config['TWITTER_IDS']['consumer']."',
										'".$config['TWITTER_IDS']['consumersecret']."',
										'".$config['TWITTER_IDS']['accesstoken']."',
										'".$config['TWITTER_IDS']['accesstokensecret']."')");


  mysqli_query($db, "UPDATE obs_scopes SET scope_twittercontent = '".$config['TWITTER_CONTENT']."'");
  echo "<ul><li>Le fichier config.php peut être nettoyé, supprimez tout son contenu sauf le bloc /* Database configuration */ (MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE, MYSQL_CHARSET)</li><li>Supprimez le fichier upgrade.php du dossier app</li></ul>";
}

