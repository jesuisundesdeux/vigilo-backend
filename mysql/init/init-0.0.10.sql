ALTER TABLE `obs_list` ADD `obs_city` SMALLINT(4) NOT NULL AFTER `obs_scope`;
CREATE TABLE `obs_status_update` (
	  `status_update_id` int(11) NOT NULL,
	  `status_update_obsid` int(11) NOT NULL,
	  `status_update_status` int(11) NOT NULL DEFAULT 0,
	  `status_update_comment` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
	  `status_update_time` bigint(20) NOT NULL DEFAULT current_timestamp(),
	  `status_update_roleid` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT '0',
	  `status_update_hasphoto` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


ALTER TABLE `obs_status_update`
  ADD KEY `status_update_id` (`status_update_id`),
  ADD PRIMARY KEY (`status_update_id`);

ALTER TABLE `obs_status_update`
  MODIFY `status_update_id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

UPDATE `obs_config` SET `config_value` = '0.0.10' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

