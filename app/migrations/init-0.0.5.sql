--------------------
-- init 0.0.5
--------------------


ALTER TABLE `obs_list` CHANGE `obs_app_version` `obs_app_version` VARCHAR(50) NOT NULL;
ALTER TABLE `obs_config` ADD PRIMARY KEY( `config_id`);
UPDATE `obs_config` SET `config_value` = '0.0.5' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
