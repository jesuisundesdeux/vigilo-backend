--------------------
-- init 0.0.23
--------------------
-- Categories of the instance: national categories disabled locally, and categories
-- added by the instance (cat_custom = 1, ids from 1000)
CREATE TABLE IF NOT EXISTS `obs_categories` (
  `cat_id` int(11) NOT NULL,
  `cat_custom` tinyint(1) NOT NULL DEFAULT 0,
  `cat_disabled` tinyint(1) NOT NULL DEFAULT 0,
  `cat_name` varchar(100) NOT NULL DEFAULT '',
  `cat_name_en` varchar(100) NOT NULL DEFAULT '',
  `cat_color` varchar(30) NOT NULL DEFAULT '',
  `cat_resolvable` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhooks: code of each category in the called tool ({{categorie_code}}), and option to
-- only send the categories that have one
-- (ADD COLUMN IF NOT EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_webhooks` ADD COLUMN `webhook_category_map` text NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_webhooks' AND COLUMN_NAME = 'webhook_category_map');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;
-- (ADD COLUMN IF NOT EXISTS does not exist in MySQL: conditional statement)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_webhooks` ADD COLUMN `webhook_category_only` tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_webhooks' AND COLUMN_NAME = 'webhook_category_only');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.23' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
