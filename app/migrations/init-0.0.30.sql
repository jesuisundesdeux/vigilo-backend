--------------------
-- init 0.0.30
--------------------
-- Webhooks: optional filters on the categories and the cities of the observations
-- (comma-separated ids, empty = all; city 0 = observations without a known city)
-- (ADD COLUMN IF NOT EXISTS does not exist in MySQL: conditional statements)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_webhooks` ADD COLUMN `webhook_categories` varchar(1000) NOT NULL DEFAULT \'\'', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_webhooks' AND COLUMN_NAME = 'webhook_categories');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_webhooks` ADD COLUMN `webhook_cities` varchar(1000) NOT NULL DEFAULT \'\'', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_webhooks' AND COLUMN_NAME = 'webhook_cities');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.30' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
