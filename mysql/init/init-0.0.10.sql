ALTER TABLE `obs_roles` ADD UNIQUE(`role_login`); 
ALTER TABLE `obs_list` ADD `obs_status_resolved_time` BIGINT NOT NULL AFTER `obs_status`, ADD `obs_status_resolved_comment` VARCHAR(255) NOT NULL AFTER `obs_status_resolved_time`, ADD `obs_status_resolved_hasphoto` BOOLEAN NOT NULL AFTER `obs_status_resolved_comment`;
ALTER TABLE `obs_list` CHANGE `obs_status_resolved_hasphoto` `obs_status_resolved_hasphoto` TINYINT(1) NOT NULL DEFAULT '0';

UPDATE `obs_config` SET `config_value` = '0.0.10' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

