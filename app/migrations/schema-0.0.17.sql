-- Schema of a new database at version 0.0.17, applied to an empty database only
-- (includes/migrations.php). It replaces the migrations 0.0.2 to 0.0.17: no instance
-- in service is older than 0.0.17 (issue #328). The migrations init-0.0.18.sql and
-- later then bring it to the code version. A database older than 0.0.17 is refused.
SET SESSION innodb_strict_mode=OFF;

CREATE TABLE IF NOT EXISTS `obs_cities` (
  `city_id` int(11) NOT NULL AUTO_INCREMENT,
  `city_scope` int(11) NOT NULL,
  `city_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `city_postcode` mediumint(9) NOT NULL DEFAULT 0,
  `city_area` float NOT NULL DEFAULT 0,
  `city_population` int(11) NOT NULL DEFAULT 0,
  `city_website` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;
CREATE TABLE IF NOT EXISTS `obs_config` (
  `config_id` int(11) NOT NULL AUTO_INCREMENT,
  `config_param` varchar(255) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  PRIMARY KEY (`config_id`),
  UNIQUE KEY `config_param` (`config_param`),
  KEY `config_id` (`config_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;
CREATE TABLE IF NOT EXISTS `obs_list` (
  `obs_id` int(11) NOT NULL AUTO_INCREMENT,
  `obs_scope` varchar(255) NOT NULL,
  `obs_city` smallint(4) NOT NULL,
  `obs_cityname` varchar(255) NOT NULL,
  `obs_coordinates_lat` varchar(255) NOT NULL,
  `obs_coordinates_lon` varchar(255) NOT NULL,
  `obs_address_string` varchar(255) NOT NULL,
  `obs_comment` varchar(255) NOT NULL,
  `obs_explanation` text NOT NULL DEFAULT '',
  `obs_categorie` smallint(6) NOT NULL DEFAULT 1,
  `obs_token` varchar(30) NOT NULL,
  `obs_time` bigint(20) NOT NULL,
  `obs_status` smallint(6) NOT NULL,
  `obs_app_version` varchar(50) NOT NULL,
  `obs_approved` tinyint(1) NOT NULL DEFAULT 0,
  `obs_secretid` varchar(60) NOT NULL,
  `obs_complete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`obs_id`),
  KEY `token` (`obs_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
CREATE TABLE IF NOT EXISTS `obs_resolutions` (
  `resolution_id` int(11) NOT NULL AUTO_INCREMENT,
  `resolution_token` varchar(30) NOT NULL,
  `resolution_secretid` varchar(60) NOT NULL,
  `resolution_app_version` int(11) NOT NULL,
  `resolution_comment` varchar(255) NOT NULL,
  `resolution_time` bigint(20) NOT NULL,
  `resolution_status` smallint(6) NOT NULL,
  `resolution_withphoto` tinyint(1) NOT NULL DEFAULT 0,
  `resolution_complete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`resolution_id`),
  KEY `token` (`resolution_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
CREATE TABLE IF NOT EXISTS `obs_resolutions_tokens` (
  `restok_resolutionid` int(11) NOT NULL,
  `restok_observationid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE IF NOT EXISTS `obs_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  `role_name` varchar(45) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `role_owner` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `role_login` varchar(60) NOT NULL,
  `role_password` varchar(255) NOT NULL,
  `role_city` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;
CREATE TABLE IF NOT EXISTS `obs_scopes` (
  `scope_id` int(11) NOT NULL AUTO_INCREMENT,
  `scope_name` varchar(255) NOT NULL,
  `scope_display_name` varchar(255) NOT NULL,
  `scope_department` tinyint(4) NOT NULL,
  `scope_coordinate_lat_min` varchar(255) NOT NULL,
  `scope_coordinate_lat_max` varchar(255) NOT NULL,
  `scope_coordinate_lon_min` varchar(255) NOT NULL,
  `scope_coordinate_lon_max` varchar(255) NOT NULL,
  `scope_map_center_string` varchar(255) NOT NULL,
  `scope_map_zoom` tinyint(4) NOT NULL,
  `scope_contact_email` varchar(255) NOT NULL,
  `scope_sharing_content_text` varchar(255) NOT NULL,
  `scope_twitter` varchar(20) NOT NULL,
  `scope_twitteraccountid` int(11) NOT NULL,
  `scope_twittercontent` varchar(500) NOT NULL,
  `scope_umap_url` varchar(255) NOT NULL,
  `scope_nominatim_urlbase` varchar(255) DEFAULT 'https://nominatim.openstreetmap.org',
  PRIMARY KEY (`scope_id`),
  KEY `scope_id` (`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=DYNAMIC;
CREATE TABLE IF NOT EXISTS `obs_twitteraccounts` (
  `ta_id` int(11) NOT NULL AUTO_INCREMENT,
  `ta_consumer` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ta_consumersecret` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ta_accesstoken` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `ta_accesstokensecret` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`ta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

INSERT IGNORE INTO `obs_config` VALUES (1,'vigilo_urlbase','');
INSERT IGNORE INTO `obs_config` VALUES (2,'vigilo_http_proto','');
INSERT IGNORE INTO `obs_config` VALUES (3,'vigilo_name','Vigilo');
INSERT IGNORE INTO `obs_config` VALUES (4,'vigilo_language','fr-FR');
INSERT IGNORE INTO `obs_config` VALUES (5,'vigilo_mapquest_api','');
INSERT IGNORE INTO `obs_config` VALUES (6,'twitter_expiry_time','24');
INSERT IGNORE INTO `obs_config` VALUES (7,'mysql_charset','utf8');
INSERT IGNORE INTO `obs_config` VALUES (8,'vigilo_timezone','Europe/Paris');
INSERT IGNORE INTO `obs_config` VALUES (9,'vigilo_db_version','0.0.17');
INSERT IGNORE INTO `obs_config` VALUES (10,'vigilo_shownonapproved','1');
INSERT IGNORE INTO `obs_config` VALUES (11,'vigilo_panel','jesuisundesdeux');
