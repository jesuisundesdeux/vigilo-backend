SET time_zone = "+00:00";

CREATE TABLE `obs_list` (
 `obs_id` int(11) NOT NULL AUTO_INCREMENT,
 `obs_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
 `obs_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
 `obs_comment` text COLLATE utf8_bin NOT NULL,
 `obs_categorie` smallint(6) NOT NULL DEFAULT 1,
 `obs_token` varchar(30) COLLATE utf8_bin NOT NULL,
 `obs_time` bigint(20) NOT NULL,
 `obs_status` smallint(6) NOT NULL,
 PRIMARY KEY (obs_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

ALTER TABLE `obs_list` ADD KEY `token` (`obs_token`);