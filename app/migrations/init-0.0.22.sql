--------------------
-- init 0.0.22
--------------------
-- sgblur_url was inserted by both init-0.0.20 and init-0.0.21 on some branches:
-- make sure it exists whatever path the instance took
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('sgblur_url','');

-- Admin login throttling
CREATE TABLE IF NOT EXISTS `obs_login_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_login` varchar(60) NOT NULL DEFAULT '',
  `attempt_ip` varchar(45) NOT NULL DEFAULT '',
  `attempt_time` bigint(20) NOT NULL,
  PRIMARY KEY (`attempt_id`),
  KEY `attempt_time` (`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal of privileged actions (#75)
CREATE TABLE IF NOT EXISTS `obs_audit_log` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `audit_time` bigint(20) NOT NULL,
  `audit_login` varchar(60) NOT NULL DEFAULT '',
  `audit_role` varchar(45) NOT NULL DEFAULT '',
  `audit_ip` varchar(45) NOT NULL DEFAULT '',
  `audit_action` varchar(60) NOT NULL,
  `audit_target` varchar(255) NOT NULL DEFAULT '',
  `audit_details` text NOT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `audit_time` (`audit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting of the public API (#139)
CREATE TABLE IF NOT EXISTS `obs_rate_limit` (
  `rl_id` int(11) NOT NULL AUTO_INCREMENT,
  `rl_kind` varchar(30) NOT NULL,
  `rl_ip` varchar(45) NOT NULL,
  `rl_time` bigint(20) NOT NULL,
  PRIMARY KEY (`rl_id`),
  KEY `rl_lookup` (`rl_kind`, `rl_ip`, `rl_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moderator notes on observations, never published (#266)
CREATE TABLE IF NOT EXISTS `obs_notes` (
  `note_id` int(11) NOT NULL AUTO_INCREMENT,
  `note_obsid` int(11) NOT NULL,
  `note_time` bigint(20) NOT NULL,
  `note_login` varchar(60) NOT NULL DEFAULT '',
  `note_text` text NOT NULL,
  PRIMARY KEY (`note_id`),
  KEY `note_obsid` (`note_obsid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New settings (all keep the previous behaviour by default)
-- Observations created per IP and per 10 minutes (0: no limit)
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_ratelimit_create','60');
-- Hide observations resolved more than N days ago from the public list (0: never) (#257)
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_resolved_hide_days','0');
-- Map of the panels: osm (no API key) or mapquest (#278)
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_map_provider','auto');
-- Tile server used for the OpenStreetMap panels
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_map_tiles_url','https://tile.openstreetmap.org/{z}/{x}/{y}.png');

UPDATE `obs_config` SET `config_value` = '0.0.22' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
