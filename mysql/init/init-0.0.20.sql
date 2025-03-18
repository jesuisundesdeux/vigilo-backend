--------------------
-- init 0.0.20
--------------------
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('sgblur_url','');
UPDATE `obs_config` SET `config_value` = '0.0.20' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
