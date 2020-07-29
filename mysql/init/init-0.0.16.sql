--------------------
-- init 0.0.16
--------------------
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_panel','jesuisundesdeux');

UPDATE `obs_config` SET `config_value` = '0.0.16' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
