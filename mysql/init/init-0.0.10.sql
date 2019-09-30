

--------------------
-- init 0.0.2
--------------------


CREATE TABLE `obs_list` (
  `obs_id` int(11) NOT NULL AUTO_INCREMENT,
  `obs_scope` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_address_string` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_comment` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_explanation` text COLLATE utf8_bin NOT NULL DEFAULT '',
  `obs_categorie` smallint(6) NOT NULL DEFAULT 1,
  `obs_token` varchar(30) COLLATE utf8_bin NOT NULL,
  `obs_time` bigint(20) NOT NULL,
  `obs_status` smallint(6) NOT NULL,
  `obs_app_version` int(11) NOT NULL,
  `obs_approved` tinyint(1) NOT NULL DEFAULT 0,
  `obs_secretid` varchar(60) COLLATE utf8_bin NOT NULL,
  `obs_complete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`obs_id`),
  KEY `token` (`obs_token`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE `obs_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(255) CHARACTER SET latin1 NOT NULL,
  `role_name` varchar(45) CHARACTER SET latin1 NOT NULL,
  `role_owner` varchar(255) CHARACTER SET latin1 NOT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

--------------------
-- init 0.0.3
--------------------


ALTER TABLE `obs_roles` ADD `role_login` VARCHAR(60) NOT NULL AFTER `role_owner`;
ALTER TABLE `obs_roles` ADD `role_password` VARCHAR(255) NOT NULL AFTER `role_login`;



--------------------
-- init 0.0.4
--------------------



CREATE TABLE `obs_scopes` (
  `scope_id` int(11) NOT NULL,
  `scope_name` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_display_name` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_coordinate_lat_min` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_coordinate_lat_max` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_coordinate_lon_min` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_coordinate_lon_max` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_map_center_string` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_map_zoom` tinyint(4) NOT NULL,
  `scope_contact_email` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_sharing_content_text` varchar(255) COLLATE utf8_bin NOT NULL,
  `scope_umap_url` varchar(255) COLLATE utf8_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;


ALTER TABLE `obs_scopes`
  ADD KEY `scope_id` (`scope_id`);

ALTER TABLE `obs_scopes`
  MODIFY `scope_id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE `obs_config` (
  `config_id` int(11) NOT NULL,
  `config_param` varchar(255) COLLATE utf8_bin NOT NULL,
  `config_value` varchar(255) COLLATE utf8_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

ALTER TABLE `obs_config`
  ADD KEY `config_id` (`config_id`);

ALTER TABLE `obs_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT;

INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_urlbase','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_http_proto','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_name','Vigilo');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_language','fr-FR');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_mapquest_api','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('twitter_expiry_time','24');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mysql_charset','utf8');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_timezone','Europe/Paris');

INSERT INTO `obs_config` (`config_id`, `config_param`, `config_value`) VALUES (NULL, 'vigilo_db_version', '0.0.4');


--------------------
-- init 0.0.5
--------------------


ALTER TABLE `obs_list` CHANGE `obs_app_version` `obs_app_version` VARCHAR(50) NOT NULL;
ALTER TABLE `obs_config` ADD PRIMARY KEY( `config_id`);
UPDATE `obs_config` SET `config_value` = '0.0.5' WHERE `obs_config`.`config_param` = 'vigilo_db_version';


--------------------
-- init 0.0.6
--------------------


UPDATE `obs_config` SET `config_value` = '0.0.6' WHERE `obs_config`.`config_param` = 'vigilo_db_version';


--------------------
-- init 0.0.7
--------------------


ALTER TABLE `obs_scopes` ADD PRIMARY KEY( `scope_id`);
UPDATE `obs_config` SET `config_value` = '0.0.7' WHERE `obs_config`.`config_param` = 'vigilo_db_version';


--------------------
-- init 0.0.8
--------------------


ALTER TABLE `obs_scopes` ADD `scope_twitter` VARCHAR(20) NOT NULL AFTER `scope_sharing_content_text`;
ALTER TABLE `obs_scopes` ADD `scope_department` TINYINT NOT NULL AFTER `scope_display_name`;

CREATE TABLE `obs_cities` (
  `city_id` int(11) NOT NULL,
  `city_scope` int(11) NOT NULL,
  `city_name` varchar(255) CHARACTER SET latin1 NOT NULL,
  `city_postcode` mediumint(9) NOT NULL,
  `city_area` float NOT NULL,
  `city_population` int(11) NOT NULL,
  `city_website` varchar(255) CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

ALTER TABLE `obs_cities`
  ADD PRIMARY KEY (`city_id`);

ALTER TABLE `obs_cities`
  MODIFY `city_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

UPDATE `obs_config` SET `config_value` = '0.0.8' WHERE `obs_config`.`config_param` = 'vigilo_db_version';


--------------------
-- init 0.0.9
--------------------


CREATE TABLE `obs_twitteraccounts` (
  `ta_id` int(11) NOT NULL,
  `ta_consumer` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_consumersecret` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_accesstoken` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_accesstokensecret` varchar(255) CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

ALTER TABLE `obs_twitteraccounts` ADD PRIMARY KEY(`ta_id`);

ALTER TABLE `obs_twitteraccounts`
  MODIFY `ta_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `obs_scopes` ADD `scope_twitteraccountid` INT NOT NULL AFTER `scope_twitter`;

UPDATE `obs_scopes` SET `scope_twitteraccountid` = '1';

ALTER TABLE `obs_scopes` ADD `scope_twittercontent` VARCHAR(500) NOT NULL AFTER `scope_twitteraccountid`;

ALTER TABLE `obs_config` ADD UNIQUE(`config_param`);

UPDATE `obs_config` SET `config_value` = '0.0.9' WHERE `obs_config`.`config_param` = 'vigilo_db_version';



--------------------
-- init 0.0.10
--------------------


ALTER TABLE `obs_list` ADD `obs_city` SMALLINT(4) NOT NULL AFTER `obs_scope`;
CREATE TABLE `obs_status_update` (
	  `status_update_id` int(11) NOT NULL,
	  `status_update_obsid` int(11) NOT NULL,
	  `status_update_status` int(11) NOT NULL DEFAULT 0,
	  `status_update_comment` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
	  `status_update_time` bigint(20) NOT NULL,
	  `status_update_roleid` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT '0',
	  `status_update_hasphoto` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


ALTER TABLE `obs_status_update`
  ADD KEY `status_update_id` (`status_update_id`),
  ADD PRIMARY KEY (`status_update_id`);

ALTER TABLE `obs_status_update`
  MODIFY `status_update_id` int(11) NOT NULL AUTO_INCREMENT;

UPDATE `obs_config` SET `config_value` = '0.0.10' WHERE `obs_config`.`config_param` = 'vigilo_db_version';



