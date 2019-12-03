--------------------
-- init 0.0.14
--------------------
ALTER TABLE obs_scopes ADD scope_nominatim_urlbase VARCHAR(255) DEFAULT "https://nominatim.openstreetmap.org";

DROP TABLE `obs_status_update`;

CREATE TABLE `obs_resolutions` (
    `resolution_id` int(11) NOT NULL AUTO_INCREMENT,
    `resolution_token` varchar(30) COLLATE utf8_bin NOT NULL,
    `resolution_secretid` varchar(60) COLLATE utf8_bin NOT NULL,
    `resolution_app_version` int(11) NOT NULL,
    `resolution_comment` varchar(255) COLLATE utf8_bin NOT NULL,
    `resolution_time` bigint(20) NOT NULL,
    `resolution_status` smallint(6) NOT NULL,
    `resolution_complete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`resolution_id`),
  KEY `token` (`resolution_token`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;


CREATE TABLE `obs_resolutions_tokens` (
    `restok_resolutionid` int(11) NOT NULL,
    `restok_observationid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


UPDATE `obs_config` SET `config_value` = '0.0.14' WHERE `obs_config`.`config_param` = 'vigilo_db_version';