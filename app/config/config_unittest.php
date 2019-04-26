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

$config['MYSQL_HOST'] = "db";
$config['MYSQL_USER'] = "mysqluser";
$config['MYSQL_PASSWORD'] = "xxxx";
$config['MYSQL_DATABASE'] = "vigilodb";

/* Server configuration */
$config['URLBASE'] = $_SERVER['SERVER_NAME'];
$config['HTTP_PROTOCOL'] = 'https';

/* UMAP configuration
 *
 * It is important to remove any slash at the end of this url
 * With a slash, zoom to observation won't work when you click on a
 * picture from mosaic.php page.
 */
$config['UMAP_URL'] = "https://umap....#19";

/* Naming configuration */
$config['VIGILO_NAME'] = "JeSuisUnDesDeux / Vigilo";
$config['VIGILO_LANGUAGE'] = "fr-FR";

/* External MapQuest API configuration */
$config['MAPQUEST_API'] = "xxxx";

/* Approve / tweeter configuration */
$config['APPROVE_TWITTER_EXPTIME'] = 24;

/* Twitter configuration */
$config['TWITTER_IDS'] =  array("consumer" => "xxxx", 
                     "consumersecret" => "xxxx", 
                     "accesstoken" => "xxxx", 
                     "accesstokensecret" => "xxxx");

$config['TWITTER_CONTENT'] = str_replace('\n',"\n","what you want to be tweeted");