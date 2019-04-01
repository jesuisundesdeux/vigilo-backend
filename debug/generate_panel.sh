#!/bin/bash

. ./config.sh

# This script calls generate_panel.php and downloads the generated image in a local file
# Change parameters in this script and/or config.sh
#
# Example
#   ./generate_panel.sh IxgleQEj
#
#   # It will creates a IxgleQEj.png file

TOKEN=$1

curl -s "${VIGILO_SERVER}:${VIGILO_PORT}/generate_panel.php?token=${TOKEN}" -o ${TOKEN}.png

exit 0
