INSERT INTO `obs_config` (`config_param`, `config_value`) VALUES 
                  ('vigilo_urlbase', '127.0.0.1'), 
                  ('vigilo_http_proto', 'http'),
                  ('vigilo_name', 'VIGILO_NAME'),
                  ('vigilo_language', 'fr-FR'), 
                  ('vigilo_mapquest_api', 'MAPQUEST_API'),
                  ('twitter_expiry_time', '24'),
                  ('mysql_charset', 'utf8'),
                  ('vigilo_timezone', 'Europe/Paris');

INSERT INTO `obs_twitteraccounts` (`ta_consumer`, `ta_consumersecret`, `ta_accesstoken`, `ta_accesstokensecret`)
                  VALUES ('TWITTER_IDS_consumer',
                          'TWITTER_IDS_consumersecret',
                          'TWITTER_IDS_accesstoken'
                          'TWITTER_IDS_accesstokensecret');

UPDATE `obs_scopes` SET `scope_twitteraccountid` = '1';


