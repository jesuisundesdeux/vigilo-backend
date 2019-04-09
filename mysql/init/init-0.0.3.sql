ALTER TABLE `obs_list` ADD `obs_approved` tinyint(1) NOT NULL DEFAULT 0;
UPDATE `obs_list` set `obs_approved` = 1 where `obs_approved` is NULL;