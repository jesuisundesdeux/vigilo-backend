#!/bin/bash

#################################################
# Init default vars
#################################################

# # City informations
CITY_NAME="Ville de Montpellier"
CITY_URL="https://www.montpellier.fr/"

# # Association informations
ASSO_NAME="Vélocité Grand Montpellier"
ASSO_URL="https://www.velocite-montpellier.fr/"

# # Twitter account informations
TWITTER_ACCOUNT="@VelociteMtp"
TWITTER_URL="https://twitter.com/VelociteMtp"

# # Vigilo API URL
URL_VIGILO_API="https://vigilo.jesuisundesdeux.org"

#################################################
# Input vars
#################################################

# City informations
read -p "City Name: [${CITY_NAME}]" ICITY_NAME ; if [ "${ICITY_NAME}" == "" ]; then ICITY_NAME=$CITY_NAME;fi
read -p "City Url: [${CITY_URL}]" ICITY_URL ; if [ "${ICITY_URL}" == "" ]; then ICITY_URL=$CITY_URL;fi

# Association informations
read -p "Asso Name: [${ASSO_NAME}]" IASSO_NAME ; if [ "${IASSO_NAME}" == "" ]; then IASSO_NAME=$ASSO_NAME;fi
read -p "Asso Url: [${ASSO_URL}]" IASSO_URL ; if [ "${IASSO_URL}" == "" ]; then IASSO_URL=$ASSO_URL;fi

# Twitter account informations
read -p "Twitter Account: [${TWITTER_ACCOUNT}]" ITWITTER_ACCOUNT ; if [ "${ITWITTER_ACCOUNT}" == "" ]; then ITWITTER_ACCOUNT=$TWITTER_ACCOUNT;fi
read -p "Twitter Url: [${TWITTER_URL}]" ITWITTER_URL ; if [ "${ITWITTER_URL}" == "" ]; then ITWITTER_URL=$TWITTER_URL;fi

# Vigilo API URL
read -p "Vigilo Api Url: [${URL_VIGILO_API}]" IURL_VIGILO_API ; if [ "${IURL_VIGILO_API}" == "" ]; then IURL_VIGILO_API=$URL_VIGILO_API;fi

cp template.umap vigilo.umap

# City informations
sed -i "s~<<CITY_NAME>>~${ICITY_NAME}~g" vigilo.umap
sed -i "s~<<CITY_URL>>~${ICITY_URL}~g" vigilo.umap

# Association informations
sed -i "s~<<ASSO_NAME>>~${IASSO_NAME}~g" vigilo.umap
sed -i "s~<<ASSO_URL>>~${IASSO_URL}~g" vigilo.umap

# Twitter account informations
sed -i "s~<<TWITTER_ACCOUNT>>~${ITWITTER_ACCOUNT}~g" vigilo.umap
sed -i "s~<<TWITTER_URL>>~${ITWITTER_URL}~g" vigilo.umap

# Vigilo API URL
sed -i "s~<<URL_VIGILO_API>>~${IURL_VIGILO_API}~g" vigilo.umap
