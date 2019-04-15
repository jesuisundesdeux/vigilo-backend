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

/* Database configuration */

$config['MYSQL_HOST'] = getenv("MYSQL_HOST");
$config['MYSQL_USER'] = getenv("MYSQL_USER");
$config['MYSQL_PASSWORD'] = getenv("MYSQL_PASSWORD");
$config['MYSQL_DATABASE'] = getenv("MYSQL_DATABASE");

/* Server configuration */
$config['URLBASE'] = $_SERVER['SERVER_NAME'];
$config['HTTP_PROTOCOL'] = 'https';

/* UMAP configuration */
$config['UMAP_URL'] = getenv("UMAP_URL");

/* Naming configuration */
$config['VIGILO_NAME'] = getenv("VIGILO_NAME");
$config['VIGILO_LANGUAGE'] = getenv("VIGILO_LANGUAGE");

/* External MapQuest API configuration */
$config['MAPQUEST_API'] = getenv("MAPQUEST_API");

/* Approve / tweeter configuration */
$config['APPROVE_TWITTER_EXPTIME'] = getenv("TWITTER_EXPIRY_TIME");

/* Twitter configuration */
$config['TWITTER_IDS'] =  array("consumer" => getenv("TWITTER_CONSUMER"), 
                     "consumersecret" => getenv("TWITTER_CONSUMERSECRET"),
                     "accesstoken" => getenv("TWITTER_ACCESSTOKEN"),
                     "accesstokensecret" => getenv("TWITTER_ACCESSTOKENSECRET"));

$config['TWITTER_CONTENT'] = str_replace('\n',"\n",getenv("TWITTER_CONTENT"));

