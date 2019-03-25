#!/bin/bash

. ./config.sh

# Provide json output from create_issue.php
# This script will upload the given image
# Example :
#   ./add_image.sh "./image.jpg" '{"token":"IxgleQEj","status":0,"secretid":"5c193787228b0018084535","group":0}'

IMAGE_PATH="$1"
JSON="$2"

TOKEN=$(echo $JSON | jq -r ".token")
SECRETID=$(echo $JSON | jq -r ".secretid")
STATUS=$(echo $JSON | jq -r ".status")

if [ ! -e $IMAGE_PATH ]; then
  echo "Image file not found."
  exit 0
fi

curl --data-binary @$IMAGE_PATH "${VIGILO_SERVER}:${VIGILO_PORT}/add_image.php?token=${TOKEN}&secretid=${SECRETID}"

exit 0
