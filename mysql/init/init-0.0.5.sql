CREATE TABLE `obs_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_key` varchar(255) CHARACTER SET latin1 NOT NULL,
  `role_name` varchar(45) CHARACTER SET latin1 NOT NULL,
  `role_owner` varchar(255) CHARACTER SET latin1 NOT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;
