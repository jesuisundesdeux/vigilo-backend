--------------------
-- init 0.0.21
--------------------
-- add the mastodon url and the mastodon token entries to the config table
-- INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mastodon_url','');
-- INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('mastodon_token','');

-- rename the twitter table to social_media
RENAME TABLE obs_twitteraccounts TO obs_social_media_accounts;

ALTER TABLE obs_social_media_accounts ADD COLUMN ta_type ENUM('twitter', 'mastodon') NOT NULL DEFAULT 'twitter';
ALTER TABLE obs_social_media_accounts ADD COLUMN ta_api_url VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE obs_scopes CHANGE COLUMN scope_twitteraccountid scope_socialmediaaccountid INT(11) NOT NULL;
ALTER TABLE obs_scopes CHANGE COLUMN scope_twittercontent scope_socialcontent varchar(500) NOT NULL;
ALTER TABLE obs_scopes CHANGE COLUMN scope_twitter scope_socialname varchar(500) NOT NULL;

UPDATE obs_config SET config_param = 'social_media_expiry_time' WHERE config_param = 'twitter_expiry_time';

UPDATE `obs_config` SET `config_value` = '0.0.20' WHERE `obs_config`.`config_param` = 'vigilo_db_version';