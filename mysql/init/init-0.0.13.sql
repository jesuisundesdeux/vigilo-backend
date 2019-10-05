--------------------
-- init 0.0.13
--------------------
ALTER TABLE `obs_list` ADD `obs_cityname` VARCHAR(255) NOT NULL AFTER `obs_city`;
ALTER TABLE `obs_roles` ADD `role_city` VARCHAR(255) NOT NULL AFTER `role_password`;

UPDATE `obs_config` SET `config_value` = '0.0.13' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
INSERT INTO obs_config (config_param,config_value) VALUES ("migration_from_version",'0.0.12');
