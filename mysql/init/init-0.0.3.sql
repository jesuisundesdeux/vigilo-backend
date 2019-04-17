ALTER TABLE `obs_roles` ADD `role_login` VARCHAR(60) NOT NULL AFTER `role_owner`;
ALTER TABLE `obs_roles` ADD `role_password` VARCHAR(255) NOT NULL AFTER `role_login`;

