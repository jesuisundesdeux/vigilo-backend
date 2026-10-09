--------------------
-- init 0.0.28
--------------------
-- Scopes: URL of the website of the association that runs the territory (get_scope.php association_url)
-- (ADD COLUMN IF NOT EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_scopes` ADD COLUMN `scope_association_url` varchar(255) NOT NULL DEFAULT \'\'', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_scopes' AND COLUMN_NAME = 'scope_association_url');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.28' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
