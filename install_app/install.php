<?php
require_once('config/config.php');

function deleteInstallFile() {
  unlink('./install.php');
}
?>

<html>
 <head>
  <title>Initialisation instance</title>
 </head>
 <body>

<?php
if(!$db = mysqli_connect($config['MYSQL_HOST'],
                     $config['MYSQL_USER'],
                     $config['MYSQL_PASSWORD'],
                     $config['MYSQL_DATABASE'])) {
  echo "Erreur de connexion à la base de données MySQL : <br />
        - Si vous êtes sur docker, veuillez compléter le fichier .env <br />
        - Si vous êtes en mode hebergé, veuillez compléter le fichier config/config.php";
}
else {
  $query_installed = mysqli_query($db,"SELECT * FROM obs_config WHERE config_param='vigilo_urlbase'");
  if(mysqli_num_rows($query_installed) != 0) {
    echo "Instance déjà configurée / veuillez supprimer le fichier install.php";
  } 
  else { 
    if(!empty($_POST)) {
      /* Set config */		
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_urlbase','".$_POST['urlbase']."')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_http_proto','".$_POST['http_proto']."')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_name','".$_POST['instancename']."')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_language','fr-FR')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_mapquest_api','".$_POST['mapquest_api']."')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('twitter_expiry_time','24')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mysql_charset','utf8')");
      mysqli_query($db, "INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_timezone','Europe/Paris')");
  
      /* Set Twitter account */
      mysqli_query($db, "INSERT INTO obs_twitteraccounts (`ta_consumer`,`ta_consumersecret`,`ta_accesstoken`,`ta_accesstokensecret`) VALUES ('".$_POST['twitter_consumer']."','".$_POST['twitter_consumersecret']."','".$_POST['twitter_accesstoken']."','".$_POST['twitter_accesstokensecret']."')") or die(mysqli_error($db));
  
  
      /* Set scope */
      mysqli_query($db, "INSERT INTO obs_scopes (`scope_name`,
  	                                      `scope_display_name`,
  					      `scope_department`,
  					      `scope_coordinate_lat_min`,
  					      `scope_coordinate_lat_max`,
  					      `scope_coordinate_lon_min`,
  					      `scope_coordinate_lon_max`,
  					      `scope_map_center_string`,
  					      `scope_map_zoom`,
  					      `scope_contact_email`,
  					      `scope_sharing_content_text`,
  					      `scope_twitter`,
  					      `scope_twitteraccountid`,
  					      `scope_twittercontent`,
  					      `scope_umap_url`)
  				VALUES ('".$_POST['scope_name']."',
                                          '".$_POST['scope_display_name']."',
                                          '".$_POST['scope_departement']."',
                                          '".$_POST['scope_lat_min']."',
                                          '".$_POST['scope_lat_max']."',
                                          '".$_POST['scope_lon_min']."',
                                          '".$_POST['scope_lon_max']."',
                                          '".$_POST['scope_mapcenter']."',
                                          '".$_POST['scope_mapzoom']."',
                                          '".$_POST['scope_contactemail']."',
  					'".$_POST['scope_sharing_content_text']."',
  					'".$_POST['twitter_accountname']."',
  					'1',
  					'".$_POST['twitter_twittercontent']."',
                                          '".$_POST['scope_mapurl']."')") or die(mysqli_error($db));
  
      echo "Configuration mise en place / veuillez supprimer le fichier install.php";
    }
    else {
  ?>
    <form action="/install.php" method="POST">
      <p>Domaine principal (exemple "api.vigilo.jesuisundesdeux.org") : <input type="text" name="urlbase" /></p>
      <p>Protocole HTTP (exemple : "https") : <input type="text" name="http_proto" /></p>
      <p>Nom de l'instance (exemple: JeSuisUnDesDeux / Vigilo): <input type="text" name="instancename" /></p>
      <p>Clé API Mapquest : <input type="text" name="mapquest_api" /></p>
      <p>Nom du compte Twitter affiché dans l'app : <input type="text" name="twitter_accountname" /></p>
      <p>Identifiant Twitter "consumer" : <input type="text" name="twitter_consumer" /></p>
      <p>Identifiant Twitter "consumersecret" : <input type="text" name="twitter_consumersecret" /></p>
      <p>Identifiant Twitter "accesstoken" : <input type="text" name="twitter_accesstoken" /></p>
      <p>Identifiant Twitter "accesstokensecret" : <input type="text" name="twitter_accesstokensecret" /></p>
      <p>Texte partagé par le compte Twitter : <input type="text" name="twitter_twittercontent" /></p>
      <p>Identifiant du scope (exemple 34_montpellier): <input type="text" name="scope_name" /></p>
      <p>Nom du scope (Montpellier): <input type="text" name="scope_display_name" /></p>
      <p>Departement du scope (34): <input type="text" name="scope_departement" /></p>
      <p>Latitude min (exemple : 43.202367): <input type="text" name="scope_lat_min" /></p>
      <p>Latitude max (exemple : 43.402367): <input type="text" name="scope_lat_max" /></p>
      <p>Longitude min (exemple : 5.082367): <input type="text" name="scope_lon_min" /></p>
      <p>Longitude max (exemple : 5.202367): <input type="text" name="scope_lon_max" /></p>
      <p>Coordonnées centre carte (exemple : 43.299037, 5.371397): <input type="text" name="scope_mapcenter" /></p>
      <p>MAP Zoom (15): <input type="text" name="scope_mapzoom" /></p>
      <p>Mail de contact: <input type="text" name="scope_contactemail" /></p>
      <p>Texte par défaut dans les partages: <input type="text" name="scope_sharing_content_text" /></p>
      <p>Lien carte: <input type="text" name="scope_mapurl" /></p>
      <p>Valider : <input type="submit" value="Valider" /></p>
    </form>
  
  <?php
    }
  }
}

?>
 </body>
</html>

