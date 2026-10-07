--------------------
-- init 0.0.19
--------------------
CREATE INDEX resolutionid on obs_resolutions_tokens(restok_resolutionid);
CREATE INDEX obsid on obs_resolutions_tokens(restok_observationid );
CREATE INDEX city on obs_list(obs_city); 

UPDATE `obs_config` SET `config_value` = '0.0.19' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
