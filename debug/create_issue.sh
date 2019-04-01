#!/bin/bash

. ./config.sh

# This script creates an issue for testing purpose
# Change parameters in this script and/or config.sh

TOKEN=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 8 | head -n 1)
DATE=$(date "+%s")

COMMENT="Comment test"
POSTAL_ADDRESS="42 rue du vélo"
CATEGORY=1

curl --data "coordinates_lat=${LAT}" \
     --data "coordinates_lon=${LON}" \
     --data "comment=${COMMENT}" \
     --data "categorie=${CATEGORY}" \
     --data "token=${TOKEN}" \
     --data "time=${DATE}" \
     --data "address=${POSTAL_ADDRESS}" \
     -X POST \
     "${VIGILO_SERVER}:${VIGILO_PORT}/create_issue.php"

exit 0
