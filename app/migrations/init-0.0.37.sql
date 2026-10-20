--------------------
-- init 0.0.37
--------------------
-- Notes of a problem: private (admin only, default, as before) or shown in the reports;
-- a shown note can mark a milestone (site visit, meeting with the city…) on the timelines of the reports.
-- note_milestone: label ('' = not a milestone); note_milestone_time: date of the milestone (0 = date of the note)
SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_notes` ADD COLUMN `note_private` tinyint(1) NOT NULL DEFAULT 1', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_notes' AND COLUMN_NAME = 'note_private');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_notes` ADD COLUMN `note_milestone` varchar(200) NOT NULL DEFAULT \'\'', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_notes' AND COLUMN_NAME = 'note_milestone');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_notes` ADD COLUMN `note_milestone_time` bigint(20) NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_notes' AND COLUMN_NAME = 'note_milestone_time');
PREPARE vigilo_stmt FROM @vigilo_sql;
EXECUTE vigilo_stmt;
DEALLOCATE PREPARE vigilo_stmt;

UPDATE `obs_config` SET `config_value` = '0.0.37' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
