--------------------
-- init 0.0.19
--------------------

CREATE TABLE `obs_categories_local` (
    `categorie_local_id` int(11) NOT NULL AUTO_INCREMENT,
    `categorie_local_name_fr` varchar(100) COLLATE utf8_bin NOT NULL,
    `categorie_local_name_en` varchar(100) COLLATE utf8_bin NOT NULL,
    `categorie_local_color` varchar(20) COLLATE utf8_bin NOT NULL,
    `categorie_local_resolvable` varchar(20) COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`categorie_local_id`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE `obs_categories` (
    `categorie_isinternal` tinyint(1) NOT NULL DEFAULT 0,
    `categorie_id` int(11) NOT NULL,
    `categorie_order` int(11) NOT NULL DEFAULT 100,
  KEY `categorie_id` (`categorie_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

INSERT INTO  `obs_categories` (`categorie_id`,
                               `categorie_order`)
       VALUES (8,0), (3,1), (4,2), (5,3), (2,4), (6,6), (9,5), (100,11), (10,9), (11, 8), (7,7);

UPDATE `obs_config` SET `config_value` = '0.0.19' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
