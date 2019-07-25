ALTER TABLE `obs_twitteraccounts` ADD PRIMARY KEY(`ta_id`);
ALTER TABLE `obs_cities` ADD PRIMARY KEY(`city_id`);

UPDATE `obs_config` SET `config_value` = '0.0.11' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
