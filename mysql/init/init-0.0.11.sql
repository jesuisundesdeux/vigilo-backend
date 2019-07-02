INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_urlbase','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_http_proto','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_name','Vigilo');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_language','fr-FR');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_mapquest_api','');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('twitter_expiry_time','24');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mysql_charset','utf8');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_timezone','Europe/Paris');
UPDATE `obs_config` SET `config_value` = '0.0.11' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

