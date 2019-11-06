--------------------
-- init 0.0.14
--------------------
ALTER TABLE obs_scopes ADD scope_nominatim_urlbase VARCHAR(255) DEFAULT "https://nominatim.openstreetmap.org";
UPDATE `obs_config` SET `config_value` = '0.0.14' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

