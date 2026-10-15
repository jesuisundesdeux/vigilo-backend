--------------------
-- init 0.0.34
--------------------
-- Links to set the password of an account (admin, page Comptes): one-time, limited in time.
-- link_hash: SHA-256 of the token (the token itself is never stored); link_used: time of use, 0 = not used
CREATE TABLE IF NOT EXISTS `obs_password_links` (
  `link_id` int(11) NOT NULL AUTO_INCREMENT,
  `link_roleid` int(11) NOT NULL,
  `link_hash` char(64) NOT NULL,
  `link_created` bigint(20) NOT NULL DEFAULT 0,
  `link_created_login` varchar(60) NOT NULL DEFAULT '',
  `link_expires` bigint(20) NOT NULL DEFAULT 0,
  `link_used` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `link_hash` (`link_hash`),
  KEY `link_roleid` (`link_roleid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `obs_config` SET `config_value` = '0.0.34' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
