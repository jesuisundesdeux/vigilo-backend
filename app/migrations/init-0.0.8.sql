--------------------
-- init 0.0.8
--------------------


ALTER TABLE `obs_scopes` ADD `scope_twitter` VARCHAR(20) NOT NULL AFTER `scope_sharing_content_text`;
ALTER TABLE `obs_scopes` ADD `scope_department` TINYINT NOT NULL AFTER `scope_display_name`;

CREATE TABLE `obs_cities` (
  `city_id` int(11) NOT NULL,
  `city_scope` int(11) NOT NULL,
  `city_name` varchar(255) CHARACTER SET latin1 NOT NULL,
  `city_postcode` mediumint(9) NOT NULL,
  `city_area` float NOT NULL,
  `city_population` int(11) NOT NULL,
  `city_website` varchar(255) CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

ALTER TABLE `obs_cities`
  ADD PRIMARY KEY (`city_id`);

ALTER TABLE `obs_cities`
  MODIFY `city_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

UPDATE `obs_config` SET `config_value` = '0.0.8' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
