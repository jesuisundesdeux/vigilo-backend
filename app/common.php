<?php
/*
Copyright (C) 2019 Velocité Montpellier

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
 any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

define('BACKEND_VERSION','1');

date_default_timezone_set('Europe/Paris');

$db=mysqli_connect(getenv("MYSQL_HOST"),getenv("MYSQL_USER"),getenv("MYSQL_PASSWORD"),getenv("MYSQL_DATABASE"));

mysqli_set_charset($db, 'utf8' );

$urlbase = $_SERVER['SERVER_NAME'];
$umap_url = getenv("UMAP_URL");

# Generate Maps
$mapquestapi_key=getenv("MAPQUEST_API");

# Twitter configuration
$twitter_ids = array("consumer" => getenv("TWITTER_CONSUMER"), 
                     "consumersecret" => getenv("TWITTER_CONSUMERSECRET"),
                     "accesstoken" => getenv("TWITTER_ACCESSTOKEN"),
                     "accesstokensecret" => getenv("TWITTER_ACCESSTOKENSECRET"));

$tweet_content = str_replace('\n',"\n",getenv("TWITTER_CONTENT"));

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

# Categories
$categorie_lst = array(
array("catid" => 8,"catname" => "Absence d'aménagement"),
array("catid" => 3,"catname" => "Aménagement mal conçu"),
array("catid" => 4,"catname" => "Défaut d'entretien"),
array("catid" => 5,"catname" => "Absence d'arceaux de stationnement"),
array("catid" => 2,"catname" => "Véhicule ou objet gênant"),
array("catid" => 9,"catname" => "Accident, chute, incident"),
array("catid" => 6,"catname" => "Signalisation, marquage"),
array("catid" => 7,"catname" => "Incivilité récurrente sur la route"),
array("catid" => 100,"catname" => "Autre")
);

# ACL
$acls = array();
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while($roles_result = mysqli_fetch_array($roles_query)) {
  $role = $roles_result['role_name'];
  $acls[$role][] = $roles_result['role_key'];
}

