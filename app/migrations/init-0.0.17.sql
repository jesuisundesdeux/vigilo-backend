--------------------
-- init 0.0.17
--------------------
ALTER TABLE obs_roles ALTER role_city SET DEFAULT '';
ALTER TABLE obs_roles ALTER role_key SET DEFAULT '';

UPDATE `obs_config` SET `config_value` = '0.0.17' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
