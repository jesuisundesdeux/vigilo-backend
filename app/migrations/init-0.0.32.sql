--------------------
-- init 0.0.32
--------------------
-- Problems (#327): observations grouped by the administrators under a structured problem
-- (zone, categories, period, priority, status). Admin only, never published.
-- problem_categories: comma-separated catids (national and custom ones)
-- problem_zone: 'circle' (center + radius in meters) or 'polygon' (JSON [[lat, lon], ...])
-- problem_end: NULL = no end
CREATE TABLE IF NOT EXISTS `obs_problems` (
  `problem_id` int(11) NOT NULL AUTO_INCREMENT,
  `problem_title` varchar(200) NOT NULL DEFAULT '',
  `problem_description` text NOT NULL,
  `problem_categories` varchar(1000) NOT NULL DEFAULT '',
  `problem_zone` varchar(10) NOT NULL DEFAULT 'circle',
  `problem_lat` double NOT NULL DEFAULT 0,
  `problem_lon` double NOT NULL DEFAULT 0,
  `problem_radius` int(11) NOT NULL DEFAULT 0,
  `problem_polygon` text NOT NULL,
  `problem_start` bigint(20) NOT NULL DEFAULT 0,
  `problem_end` bigint(20) DEFAULT NULL,
  `problem_priority` tinyint(4) NOT NULL DEFAULT 2,
  `problem_status` varchar(20) NOT NULL DEFAULT 'open',
  `problem_created_time` bigint(20) NOT NULL DEFAULT 0,
  `problem_created_login` varchar(60) NOT NULL DEFAULT '',
  `problem_updated_time` bigint(20) NOT NULL DEFAULT 0,
  `problem_updated_login` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`problem_id`),
  KEY `problem_status` (`problem_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Observations of a problem. One problem per observation for now (unique po_obsid: to drop
-- to allow several). po_mode: 'retro' (retroactive attribution), 'auto' (at publication),
-- 'manual'
CREATE TABLE IF NOT EXISTS `obs_problem_observations` (
  `po_problemid` int(11) NOT NULL,
  `po_obsid` int(11) NOT NULL,
  `po_mode` varchar(10) NOT NULL DEFAULT 'manual',
  `po_time` bigint(20) NOT NULL DEFAULT 0,
  `po_login` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`po_problemid`, `po_obsid`),
  UNIQUE KEY `po_obsid` (`po_obsid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `obs_config` SET `config_value` = '0.0.32' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
