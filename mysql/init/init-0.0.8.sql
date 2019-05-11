ALTER TABLE `obs_scopes` ADD `scope_twitter` VARCHAR(20) NOT NULL AFTER `scope_sharing_content_text`;
ALTER TABLE `obs_scopes` ADD `scope_department` TINYINT NOT NULL AFTER `scope_display_name`;

UPDATE `obs_config` SET `config_value` = '0.0.8' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
