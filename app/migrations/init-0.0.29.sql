--------------------
-- init 0.0.29
--------------------
-- Archived observations: kept for the statistics (get_issues.php?archived=1), no longer listed
-- (ADD COLUMN IF NOT EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_list` ADD COLUMN `obs_archived` tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_list' AND COLUMN_NAME = 'obs_archived');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.29' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
