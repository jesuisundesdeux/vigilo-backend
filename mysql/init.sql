CREATE DATABASE IF NOT EXISTS dbname;

USE dbname;
CREATE TABLE `obs_list` (
 `obs_id` int(11) AUTO_INCREMENT NOT NULL,
 `obs_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
 `obs_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
 `obs_comment` text COLLATE utf8_bin NOT NULL,
 `obs_categorie` smallint(6) NOT NULL DEFAULT 1,
 `obs_token` varchar(30) COLLATE utf8_bin NOT NULL,
 `obs_time` bigint(20) NOT NULL,
 `obs_status` smallint(6) NOT NULL,
 PRIMARY KEY (obs_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

ALTER TABLE `obs_list`
 ADD KEY `token` (`obs_token`);

 INSERT INTO `obs_list` (`obs_id`, `obs_coordinates_lat`, `obs_coordinates_lon`, `obs_comment`, `obs_categorie`, `obs_token`, `obs_time`, `obs_status`) VALUES
(19, '43.60063364932313', '3.9004433155059948', 'Parking sur le trottoir ', 1, 'ttueacArvsHCA81zNHD4tN8KvVj63l', 1547298289, 0);