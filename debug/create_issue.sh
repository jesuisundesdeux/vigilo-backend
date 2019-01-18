#!/bin/bash
LAT=43.6029503
LON=3.8822349

curl -d "coordinates_lat=${LAT}&coordinates_lon=${LON}&comment=comment&categorie=1" -X POST 'http://localhost/create_issue.php'
