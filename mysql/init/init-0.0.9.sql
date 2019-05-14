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

UPDATE `obs_scopes` SET `scope_twitteraccountid` = '1';

ALTER TABLE `obs_scopes` ADD `scope_twittercontent` VARCHAR(500) NOT NULL AFTER `scope_twitteraccount`;

UPDATE `obs_scopes` SET `scope_twittercontent` = '[COMMENT]\\n\\n- Obs similaires : https://vigilo.jesuisundesdeux.org/mosaic.php?t=[TOKEN]\\n- Carte : https://umap.openstreetmap.fr/en/map/vigilo_286846#19/[COORDINATES_LAT]/[COORDINATES_LON] \\n\\n#Montpellier #JeSuisUnDesDeux #VG_[TOKEN]';

ALTER TABLE `obs_config` ADD UNIQUE(`config_param`);

UPDATE `obs_config` SET `config_value` = '0.0.9' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

