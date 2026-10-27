--------------------
-- init 0.0.40
--------------------
-- Visit statistics: one counter per public API route and per day (instance timezone), incremented
-- on each call (includes/common.php). Compact (one row per route per day), no row-per-request table
-- to prune. Shown in the admin page "Fréquentation" (app/admin/inc/visits.php).
CREATE TABLE IF NOT EXISTS `obs_api_stats` (
  `stat_date` date NOT NULL,
  `stat_route` varchar(40) NOT NULL,
  `stat_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`stat_date`, `stat_route`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `obs_config` SET `config_value` = '0.0.40' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
