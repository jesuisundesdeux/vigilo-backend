<?php
$db=mysqli_connect('db','root','root','dbname');

# Generate Maps
$mapquestapi_key=getenv("MAPQUEST_API");