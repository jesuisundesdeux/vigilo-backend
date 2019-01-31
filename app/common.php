<?php
$db=mysqli_connect(getenv("DB_HOST"),getenv("DB_USER"),getenv("DB_PASS"),getenv("DB_DBNAME"));
mysqli_set_charset($db, 'utf8' );

# Generate Maps
$mapquestapi_key=getenv("MAPQUEST_API");
