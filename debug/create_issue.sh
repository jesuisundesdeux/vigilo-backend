#!/bin/bash

# Montpellier
LAT=43.6029503
LON=3.8822349

# Châtillon
LAT=48.80399
LON=2.28887

TOKEN=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 8 | head -n 1)
DATE=$(date "+%s")
PORT=80

curl --data "coordinates_lat=${LAT}" \
     --data "coordinates_lon=${LON}" \
     --data "comment=comment" \
     --data "comment=comment" \
     --data "categorie=1" \
     --data "token=${TOKEN}" \
     --data "time=${DATE}" \
     --data "address='42 rue du vélo'" \
     -X POST \
     'http://localhost:${PORT}/create_issue.php'

exit 0
