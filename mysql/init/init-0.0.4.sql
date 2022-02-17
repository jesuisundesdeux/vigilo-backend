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

ALTER TABLE obs_scopes ROW_FORMAT=DYNAMIC;

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
