--------------------
-- init 0.0.7
--------------------


ALTER TABLE `obs_scopes` ADD PRIMARY KEY( `scope_id`);
UPDATE `obs_config` SET `config_value` = '0.0.7' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
