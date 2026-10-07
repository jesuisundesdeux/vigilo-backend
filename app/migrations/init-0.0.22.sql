--------------------
-- init 0.0.22
--------------------
-- sgblur_url was inserted by both init-0.0.20 and init-0.0.21 on some branches:
-- make sure it exists whatever path the instance took
INSERT IGNORE INTO obs_config (`config_param`,`config_value`) VALUES ('sgblur_url','');
UPDATE `obs_config` SET `config_value` = '0.0.22' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
