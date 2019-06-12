ALTER TABLE `obs_roles` ADD UNIQUE(`role_login`); 
--ALTER TABLE `obs_list` ADD `obs_status_resolved_time` BIGINT NOT NULL AFTER `obs_status`, ADD `obs_status_resolved_comment` VARCHAR(255) NOT NULL AFTER `obs_status_resolved_time`, ADD `obs_status_resolved_hasphoto` BOOLEAN NOT NULL AFTER `obs_status_resolved_comment`;
--ALTER TABLE `obs_list` CHANGE `obs_status_resolved_hasphoto` `obs_status_resolved_hasphoto` TINYINT(1) NOT NULL DEFAULT '0';
ALTER TABLE `obs_list` ADD `obs_city` SMALLINT(4) NOT NULL AFTER `obs_scope`;

--ALTER TABLE `obs_list` CHANGE `obs_status_resolved_time` `obs_status_resolved_time` BIGINT(20) NULL DEFAULT NULL;
--ALTER TABLE `obs_list` CHANGE `obs_status_resolved_comment` `obs_status_resolved_comment` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NULL DEFAULT NULL;

CREATE TABLE `obs_status_update` (
	  `status_update_id` int(11) NOT NULL,
	  `status_update_obsid` int(11) NOT NULL,
	  `status_update_status` int(11) NOT NULL DEFAULT 0,
	  `status_update_resolved_comment` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL,
	  `status_update_resolved_time` bigint(20) NOT NULL DEFAULT current_timestamp(),
	  `status_update_authorrole` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT '0',
	  `status_update_hasphoto` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


ALTER TABLE `obs_status_update`
  ADD KEY `status_update_id` (`status_update_id`);


ALTER TABLE `obs_status_update`
  MODIFY `status_update_id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;



UPDATE `obs_config` SET `config_value` = '0.0.10' WHERE `obs_config`.`config_param` = 'vigilo_db_version';

