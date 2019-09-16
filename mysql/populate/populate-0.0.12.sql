INSERT INTO `obs_list` VALUES 
(1,'','43.637660137338784','3.8373623415827747','Rue de la Galéra, Montpellier','voiture bloquant trottoir danger pietons','',2,'5UNYMG1Y',1554444900,0,46,1,'5ca74164c650c138509887',1),
(2,'','43.605787237618955','3.867492601275444','Boulevard Renouvier, Montpellier','Every morning...','',2,'Y8QL7KC9',1554446580,0,65,1,'ff357a5b345de778f416eb',1),
(3,'','43.620590028190655','3.8333574682474136','Avenue des Moulins, Montpellier','','',2,'EUBY8K2O',1554447060,0,55,1,'5ca6fb4ddf40a083077848',1),
(4,'','43.60600403457251','3.872769512236118','Cours Gambetta, Montpellier','gène un autre cycliste','',2,'YQQVS5YL',1554447720,0,55,1,'8a3125ca2224a9e5d6534c',1),
(5,'','43.606361395818475','3.8641616329550743','Rue du Faubourg Figuerolles, Montpellier','Ah ! c\'est ma place !','',2,'9M6GGBWC',1554463189,0,55,1,'24a13e001a12d53f730401',1),
(6,'34_montpellier','43.627966395360104','3.8686265051364903','Rue du Truel, Montpellier','','',4,'4XUXXEUX',1554453300,0,46,1,'b9a0d0bba3309254bbb463',1),
(7,'34_montpellier','43.627542181092146','3.869011402130127','Rue du Truel, Montpellier','','',4,'RUDVWHTB',1554464520,0,62,1,'94edc04e12d8e9b5046459',1),
(8,'34_montpellier','43.62756693509621','3.868977203965187','Rue du Truel, Montpellier','haie sur le trottoir -> piétons sur piste cyclable','',4,'VUZYF8J3',1554453240,0,46,1,'e381756ceb8a753bd3c0f0',1),
(9,'34_montpellier','43.62690269912045','3.8699260354042053','Avenue de la Justice de Castelnau, Montpellier','peinture effacée','',6,'GNKEBNC7',1554453180,0,46,1,'e72761b8161cebd9e91b1f',1),
(10,'34_montpellier','43.629117437641405','3.8678030669689174','Rue du Truel, Montpellier','piste cyclable droite termine sur passage piéton','',3,'SI75BT6B',1554453360,0,46,1,'4d12e37e3033ed82e17aa9',1);
INSERT INTO `obs_scopes` ( `scope_name`, `scope_display_name`, `scope_coordinate_lat_min`, `scope_coordinate_lat_max`, `scope_coordinate_lon_min`, `scope_coordinate_lon_max`, `scope_map_center_string`, `scope_map_zoom`, `scope_contact_email`, `scope_sharing_content_text`, `scope_umap_url`) VALUES
('34_montpellier', 'Montpellier', '43.4569', '43.779', '3.6914', '4.1432', '43.60413756443483, 3.873367309570313', 12, 'velocite34@gmail.com', '#JeSuisUnDesDeux @montpellier3m', 'https://umap.openstreetmap.fr/en/map/vigilo_286846');
INSERT INTO `obs_config` (`config_param`, `config_value`) VALUES 
                  ('vigilo_urlbase', '127.0.0.1'), 
                  ('vigilo_http_proto', 'http'),
                  ('vigilo_name', 'VIGILO_NAME'),
                  ('vigilo_language', 'fr-FR'), 
                  ('vigilo_mapquest_api', 'MAPQUEST_API'),
                  ('twitter_expiry_time', '24'),
                  ('mysql_charset', 'utf8'),
                  ('vigilo_timezone', 'Europe/Paris');

INSERT INTO `obs_twitteraccounts` (`ta_consumer`, `ta_consumersecret`, `ta_accesstoken`, `ta_accesstokensecret`)
                  VALUES ('TWITTER_IDS_consumer',
                          'TWITTER_IDS_consumersecret',
                          'TWITTER_IDS_accesstoken',
                          'TWITTER_IDS_accesstokensecret');

UPDATE `obs_scopes` SET `scope_twitteraccountid` = '1';


insert into obs_cities (city_scope,city_name,city_postcode,city_area,city_population,city_website) VALUES ('1','Montpellier','34000','34.3','350000','www.montpellier.fr');

