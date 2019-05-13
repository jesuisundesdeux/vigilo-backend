CREATE TABLE `obs_twitteraccounts` (
  `ta_id` int(11) NOT NULL,
  `ta_consumer` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_consumersecret` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_accesstoken` varchar(255) CHARACTER SET latin1 NOT NULL,
  `ta_accesstokensecret` varchar(255) CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

ALTER TABLE `obs_twitteraccounts`
  ADD KEY `ta_id` (`ta_id`);

ALTER TABLE `obs_twitteraccounts`
  MODIFY `ta_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `obs_scopes` ADD `scope_twitteraccountid` INT NOT NULL AFTER `scope_twitter`;


/* Mettre à jour les contenus en majuscule selon ce qui a été renseigné dans config.php et executer à la main */
/*
INSERT INTO `obs_config` (`config_param`, `config_value`) VALUES 
                  ('vigilo_urlbase', 'URLBASE'), 
                  ('vigilo_http_proto', 'HTTP_PROTOCOL'),
                  ('vigilo_name', 'VIGILO_NAME'),
                  ('vigilo_language', 'VIGILO_LANGUAGE'), 
                  ('vigilo_mapquest_api', 'MAPQUEST_API'),
                  ('twitter_expiry_time', 'APPROVE_TWITTER_EXPTIME'),
                  ('mysql_charset', 'MYSQL_CHARSET'),
                  ('vigilo_timezone', 'VIGILO_TIMEZONE');

INSERT INTO `obs_twitteraccounts` (`ta_consumer`, `ta_consumersecret`, `ta_accesstoken`, `ta_accesstokensecret`)
                  VALUES ('TWITTER_IDS_consumer',
                          'TWITTER_IDS_consumersecret',
                          'TWITTER_IDS_accesstoken'
                          'TWITTER_IDS_accesstokensecret');

UPDATE `obs_scopes` SET `scope_twitteraccountid` = '1';
*/

ALTER TABLE `obs_scopes` ADD `scope_twittercontent` VARCHAR(500) NOT NULL AFTER `scope_twitteraccount`;

UPDATE `obs_scopes` SET `scope_twittercontent` = '[COMMENT]\\n\\n- Obs similaires : https://vigilo.jesuisundesdeux.org/mosaic.php?t=[TOKEN]\\n- Carte : https://umap.openstreetmap.fr/en/map/vigilo_286846#19/[COORDINATES_LAT]/[COORDINATES_LON] \\n\\n#Montpellier #JeSuisUnDesDeux #VG_[TOKEN]';

UPDATE `obs_config` SET `config_value` = '0.0.9' WHERE `obs_config`.`config_id` = 1;


COMMIT;

