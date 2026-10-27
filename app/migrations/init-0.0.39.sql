--------------------
-- init 0.0.39
--------------------
-- Per-banner "persistent" flag on obs_banners: when 1, the web app shows the banner again (and reopens
-- the launch window) on every start, even after the visitor closed it; when 0 (default, historical
-- behaviour) a closed banner stays closed until its content changes. MySQL has no ADD COLUMN IF NOT
-- EXISTS: add it only when missing so the migration stays rejouable.
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_banners` ADD COLUMN `banner_persistent` tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_banners' AND COLUMN_NAME = 'banner_persistent');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

-- Optional bold title shown above the message (replaces the former type label), configured in the admin.
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_banners` ADD COLUMN `banner_title` varchar(200) NOT NULL DEFAULT ''''', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_banners' AND COLUMN_NAME = 'banner_title');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.39' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
