ALTER TABLE `obs_list` ADD `obs_address_string` varchar(255) COLLATE utf8_bin NOT NULL;
ALTER TABLE `obs_list` ADD `obs_app_version` int(11) NOT NULL;

UPDATE `obs_list` set `obs_address_string` = 'Non défini' where `obs_address_string` = '';
UPDATE `obs_list` set `obs_app_version` = 0 where `obs_app_version` is NULL;