CREATE TABLE `obs_groups` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `group_address_string` varchar(255) CHARACTER SET latin1 NOT NULL,
  `group_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
  `group_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
  `group_categorie` int(11) NOT NULL,
  PRIMARY KEY (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

ALTER TABLE `obs_groups` ADD KEY `group_id` (`group_id`);

ALTER TABLE `obs_list` MODIFY `obs_comment` varchar(255) COLLATE utf8_bin NOT NULL;
ALTER TABLE `obs_list` ADD `obs_secretid` varchar(60) COLLATE utf8_bin NOT NULL;
ALTER TABLE `obs_list` ADD `obs_complete` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `obs_list` ADD `obs_group` int(11) NOT NULL DEFAULT 0;
