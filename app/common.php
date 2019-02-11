<?php
$db=mysqli_connect(getenv("DB_HOST"),getenv("DB_USER"),getenv("DB_PASS"),getenv("DB_DBNAME"));
mysqli_set_charset($db, 'utf8' );

# Generate Maps
$mapquestapi_key=getenv("MAPQUEST_API");

# Categories
$categorie = array(
1 => "Non défini",
2 => "Objet ou vehicule bloquant le passage",
3 => "Aménagement incohérent",
4 => "Entretien de voirie",
5 => "Zone sans stationnement vélo",
6 => "Coupure temporaire sans balisage",
7 => "Incivilité récurrente sur la route",
8 => "Aménagement exemplaire",
9 => "Zone accidentogène",
100 => "Autre");

# ACL
$roles_query = mysqli_query($db, "SELECT * FROM obs_roles");
while($roles_result = mysqli_fetch_array($roles_query)) {
  $role = $roles_result['role_name'];
  $acls[$role][] = $roles_result['role_key'];
}

