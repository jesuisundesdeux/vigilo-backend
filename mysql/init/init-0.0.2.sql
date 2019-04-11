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