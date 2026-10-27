-- 0.0.41: visiteurs uniques par jour (page Fréquentation).
-- On ne stocke jamais l'adresse IP : seulement une empreinte quotidienne (HMAC
-- avec le secret de l'instance, mêlé à la date), non réversible et impossible à
-- recouper d'un jour sur l'autre. Les visiteurs uniques d'un jour = nombre de
-- lignes de ce jour.
CREATE TABLE IF NOT EXISTS `obs_api_visitors` (
  `stat_date` date NOT NULL,
  `visitor_hash` char(32) NOT NULL,
  `visit_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`stat_date`, `visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
UPDATE `obs_config` SET `config_value` = '0.0.41' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
