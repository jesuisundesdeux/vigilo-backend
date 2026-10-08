--------------------
-- init 0.0.22
--------------------
-- Blur server: the SGBlur setting (init-0.0.20 / init-0.0.21) becomes the generic
-- vigilo_blur_url, its value kept
INSERT IGNORE INTO obs_config (`config_param`,`config_value`)
  SELECT 'vigilo_blur_url', `config_value` FROM obs_config WHERE `config_param` = 'sgblur_url';
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_blur_url','');
DELETE FROM obs_config WHERE `config_param` = 'sgblur_url';

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

-- Webhooks: HTTP calls to external services when an observation is published
CREATE TABLE IF NOT EXISTS `obs_webhooks` (
  `webhook_id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_name` varchar(100) NOT NULL DEFAULT '',
  `webhook_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `webhook_event` varchar(50) NOT NULL DEFAULT 'observation.approved',
  `webhook_method` varchar(10) NOT NULL DEFAULT 'POST',
  `webhook_url` varchar(1000) NOT NULL DEFAULT '',
  `webhook_format` varchar(10) NOT NULL DEFAULT 'json',
  `webhook_headers` text NOT NULL,
  `webhook_body` text NOT NULL,
  PRIMARY KEY (`webhook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `obs_webhook_deliveries` (
  `delivery_id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_webhookid` int(11) NOT NULL,
  `delivery_time` bigint(20) NOT NULL,
  `delivery_event` varchar(50) NOT NULL DEFAULT '',
  `delivery_token` varchar(30) NOT NULL DEFAULT '',
  `delivery_http_code` int(11) NOT NULL DEFAULT 0,
  `delivery_error` varchar(255) NOT NULL DEFAULT '',
  `delivery_duration_ms` int(11) NOT NULL DEFAULT 0,
  `delivery_response` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`delivery_id`),
  KEY `delivery_webhookid` (`delivery_webhookid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New settings (all keep the previous behaviour by default)
-- Observations created per IP and per 10 minutes (0: no limit)
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_ratelimit_create','60');
-- Hide observations resolved more than N days ago from the public list (0: never) (#257)
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_resolved_hide_days','0');

-- Twitter, panels and MapQuest are removed: their settings and the Twitter accounts
-- (API secrets) are deleted. obs_scopes.scope_twitter stays, it is still returned by
-- get_scope.php for the applications.
DROP TABLE IF EXISTS `obs_twitteraccounts`;
-- (DROP COLUMN IF EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `obs_scopes` DROP COLUMN `scope_twitteraccountid`', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_scopes' AND COLUMN_NAME = 'scope_twitteraccountid');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;
-- (DROP COLUMN IF EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `obs_scopes` DROP COLUMN `scope_twittercontent`', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_scopes' AND COLUMN_NAME = 'scope_twittercontent');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;
DELETE FROM obs_config WHERE config_param IN ('twitter_expiry_time', 'vigilo_mapquest_api', 'vigilo_panel', 'vigilo_map_provider', 'vigilo_map_tiles_url');

UPDATE `obs_config` SET `config_value` = '0.0.22' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
