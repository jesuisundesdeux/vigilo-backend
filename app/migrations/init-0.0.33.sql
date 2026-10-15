--------------------
-- init 0.0.33
--------------------
-- Problems: zone made of streets (problem_zone 'streets'), JSON [{"street": "Rue X", "city": "Ville"}, ...]
-- (city empty: any city), compared with the address of the observations
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_problems` ADD COLUMN `problem_streets` text NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_problems' AND COLUMN_NAME = 'problem_streets');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.33' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
