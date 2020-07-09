--------------------
-- init 0.0.16
--------------------
CREATE TABLE `obs_categories` (
    `cat_id` int(11) NOT NULL AUTO_INCREMENT,
    `cat_name` varchar(30) COLLATE utf8_bin NOT NULL,
    `cat_color` varchar(60) COLLATE utf8_bin NOT NULL,
    `cat_order` int(11) NOT NULL,
  PRIMARY KEY (`cat_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_categories_ext','https://vigilo-bf7f2.firebaseio.com/categorieslist.json');
INSERT INTO obs_config (`config_param`,`config_value`) VALUES ('vigilo_panel','jesuisundesdeux');

UPDATE `obs_config` SET `config_value` = '0.0.16' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
