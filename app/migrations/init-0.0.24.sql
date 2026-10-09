--------------------
-- init 0.0.24
--------------------
-- Webhooks: several events per webhook (comma-separated list), e.g.
-- 'observation.created,observation.approved,resolution.status_changed'
ALTER TABLE `obs_webhooks` MODIFY `webhook_event` varchar(255) NOT NULL DEFAULT 'observation.approved';

UPDATE `obs_config` SET `config_value` = '0.0.24' WHERE `obs_config`.`config_param` = 'vigilo_db_version';
