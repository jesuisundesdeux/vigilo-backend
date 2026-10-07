--------------------
-- init 0.0.13
--------------------
ALTER TABLE `obs_list` ADD `obs_cityname` VARCHAR(255) NOT NULL AFTER `obs_city`;
ALTER TABLE `obs_roles` ADD `role_city` VARCHAR(255) NOT NULL AFTER `role_password`;
ALTER TABLE obs_cities ALTER city_website SET DEFAULT '';
ALTER TABLE obs_cities ALTER city_population SET DEFAULT 0;
ALTER TABLE obs_cities ALTER city_area SET DEFAULT 0;
ALTER TABLE obs_cities ALTER city_postcode SET DEFAULT 0;
INSERT INTO obs_config (config_param,config_value) VALUES ('vigilo_shownonapproved',1);

UPDATE `obs_config` SET `config_value` = '0.0.13' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

