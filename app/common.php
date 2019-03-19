<?php
define('BACKEND_VERSION','1');

date_default_timezone_set('Europe/Paris');

$db=mysqli_connect(getenv("DB_HOST"),getenv("DB_USER"),getenv("DB_PASS"),getenv("DB_DBNAME"));
mysqli_set_charset($db, 'utf8' );

$urlbase = $_SERVER['SERVER_NAME'];
$umap_url = 'https://umap.openstreetmap.fr/en/map/vigilo_286846#19/';

# Generate Maps
$mapquestapi_key=getenv("MAPQUEST_API");

# Twitter configuration
$twitter_ids = array("consumer" => getenv("TWITTER_CONSUMER"), 
                     "consumersecret" => getenv("TWITTER_CONSUMERSECRET"),
                     "accesstoken" => getenv("TWITTER_ACCESSTOKEN"),
                     "accesstokensecret" => getenv("TWITTER_ACCESSTOKENSECRET"));

$tweet_content = "[COMMENT]\n\n- Obs similaires : https://vigilo.jesuisundesdeux.org/mosaic.php?t=[TOKEN]\n- Carte : https://umap.openstreetmap.fr/en/map/vigilo_286846#19/[COORDINATES_LAT]/[COORDINATES_LON] \n\n#Montpellier #JeSuisUnDesDeux #VG_[TOKEN]";


# Categories
$categorie = array(
2 => "Véhicule ou objet gênant (gcum)",
3 => "Aménagement mal conçu",
4 => "Défaut d'entretien",
5 => "Absence d'arceaux de stationnement",
6 => "Signalisation, marquage",
7 => "Incivilité récurrente sur la route",
8 => "Absence d'aménagement",
9 => "Accident, chute, incident",
100 => "Autre");

# ACL
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while($roles_result = mysqli_fetch_array($roles_query)) {
  $role = $roles_result['role_name'];
  $acls[$role][] = $roles_result['role_key'];
}

