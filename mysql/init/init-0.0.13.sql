--------------------
-- init 0.0.13
--------------------
ALTER TABLE `obs_list` ADD `obs_cityname` VARCHAR(255) NOT NULL AFTER `obs_city`;

UPDATE `obs_config` SET `config_value` = '0.0.13' WHERE `obs_config`.`config_param` = 'vigilo_db_version';


