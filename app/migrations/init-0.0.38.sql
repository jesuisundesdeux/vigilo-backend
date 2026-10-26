--------------------
-- init 0.0.38
--------------------
-- Banners (announcements) shown in the web app, moved from obs_config to their own table so that
-- several banners can be scheduled over time. banner_start / banner_end: unix timestamps, 0 = no
-- bound; banner_enabled: 0 keeps a banner without showing it; banner_type / banner_display: see
-- vigilo_banner_types() and get_banner.php. banner_scopes: comma-separated obs_scopes ids the banner
-- targets, '' = all the scopes of the instance.
CREATE TABLE IF NOT EXISTS `obs_banners` (
  `banner_id` int(11) NOT NULL AUTO_INCREMENT,
  `banner_message` text NOT NULL,
  `banner_type` varchar(20) NOT NULL DEFAULT 'info',
  `banner_display` varchar(20) NOT NULL DEFAULT 'banner',
  `banner_scopes` varchar(255) NOT NULL DEFAULT '',
  `banner_start` bigint(20) NOT NULL DEFAULT 0,
  `banner_end` bigint(20) NOT NULL DEFAULT 0,
  `banner_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `banner_created` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`banner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carry over a banner set via the former obs_config fields (0.0.38 pre-release), then drop those keys.
-- The former start/end were stored as text, not imported: the banner becomes always-on until edited.
-- Rejouable: once the obs_config keys are deleted below, the INSERT matches nothing on a re-run.
INSERT INTO `obs_banners` (`banner_message`, `banner_type`, `banner_display`, `banner_enabled`, `banner_created`)
SELECT m.config_value,
       COALESCE((SELECT config_value FROM `obs_config` WHERE config_param = 'vigilo_banner_type'), 'info'),
       COALESCE((SELECT config_value FROM `obs_config` WHERE config_param = 'vigilo_banner_display'), 'banner'),
       1, UNIX_TIMESTAMP()
FROM `obs_config` m
WHERE m.config_param = 'vigilo_banner_message' AND TRIM(m.config_value) <> '';

DELETE FROM `obs_config` WHERE `config_param` IN
  ('vigilo_banner_message', 'vigilo_banner_type', 'vigilo_banner_start', 'vigilo_banner_end', 'vigilo_banner_display');

UPDATE `obs_config` SET `config_value` = '0.0.38' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
