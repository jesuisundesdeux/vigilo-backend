<?php
require_once('includes/config.php');
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
  require_once('includes/common.php');
  if(isset($_POST)) {

  }
  else {
?>
  <form method="POST">
    <p>Domaine principal (exemple "api.vigilo.jesuisundesdeux.org") : <input type="text" name="urlbase" /></p>
    <p>Protocole HTTP (exemple : "https") : <input type="text" name="http_proto" /></p>
    <p>Nom de l'instance (exmeple: JeSuisUnDesDeux / Vigilo): <input type="text" name="instancename" /></p>
    <p>Clé API Mapquest : <input type="text" name="mapquest_api" /></p>
    <p>Heures d'expiration declanchant Tweet : <input type="text" name="mapquest_api" /></p>
    <p>Nom du compte Twitter affiché dans l'app : <input type="text" name="twitter_accountname" /></p>
    <p>Identifiant Twitter "consumer" : <input type="text" name="twitter_consumer" /></p>
    <p>Identifiant Twitter "consumersecret" : <input type="text" name="twitter_consumersecret" /></p>
    <p>Identifiant Twitter "accesstoken" : <input type="text" name="twitter_accesstoken" /></p>
    <p>Identifiant Twitter "accesstokensecret" : <input type="text" name="twitter_accesstokensecret" /></p>
    <p>Texte partagé par le compte Twitter : <input type="text" name="twitter_twittercontent" /></p>
    <p>TimeZone : <input type="text" name="mapquest_api" /></p>
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
    <p>Texte par défaut dans les partages: <input type="text" name="scope_showing_content_text" /></p>
    <p>Lien carte: <input type="text" name="scope_mapurl" /></p>
    <p>Valider : <input type="submit" value="Valider" /></p>
  </form>

<?php
  }
}

?>
 </body>
</html>

