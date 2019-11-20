--------------------
-- init 0.0.14
--------------------
ALTER TABLE obs_scopes ADD scope_nominatim_urlbase VARCHAR(255) DEFAULT "https://nominatim.openstreetmap.org";

DROP TABLE `obs_status_update`;

CREATE TABLE `obs_resolutions` (
    `resolution_id` int(11) NOT NULL,
    `resolution_token` varchar(30) COLLATE utf8_bin NOT NULL,
    `resolution_secretid` varchar(60) COLLATE utf8_bin NOT NULL,
    `resolution_app_version` int(11) NOT NULL,
    `resolution_comment` varchar(255) COLLATE utf8_bin NOT NULL,
    `resolution_time` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `obs_resolutions`
  ADD KEY `resolution_id` (`resolution_id`),
  ADD PRIMARY KEY (`status_update_id`);

ALTER TABLE `obs_resolutions`
  MODIFY `resolution_id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE `obs_resolutions_tokens` (
    `restok_resolutionid` int(11) NOT NULL,
    `restok_observationid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


UPDATE `obs_config` SET `config_value` = '0.0.14' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

