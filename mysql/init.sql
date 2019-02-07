CREATE DATABASE IF NOT EXISTS dbname;

USE dbname;

-- phpMyAdmin SQL Dump
-- version 4.7.2
-- https://www.phpmyadmin.net/
--
-- Hôte : mariadb
-- Généré le :  jeu. 07 fév. 2019 à 16:39
-- Version du serveur :  10.2.6-MariaDB-10.2.6+maria~jessie
-- Version de PHP :  7.0.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Base de données :  `mplcpobs`
--

-- --------------------------------------------------------

--
-- Structure de la table `obs_groups`
--

CREATE TABLE `obs_groups` (
  `group_id` int(11) NOT NULL,
  `group_address_string` varchar(255) CHARACTER SET latin1 NOT NULL,
  `group_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
  `group_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
  `group_categorie` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

-- --------------------------------------------------------

--
-- Structure de la table `obs_list`
--

CREATE TABLE `obs_list` (
  `obs_id` int(11) NOT NULL,
  `obs_coordinates_lat` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_coordinates_lon` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_address_string` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_comment` varchar(255) COLLATE utf8_bin NOT NULL,
  `obs_categorie` smallint(6) NOT NULL DEFAULT 1,
  `obs_token` varchar(30) COLLATE utf8_bin NOT NULL,
  `obs_time` bigint(20) NOT NULL,
  `obs_status` smallint(6) NOT NULL,
  `obs_app_version` int(11) NOT NULL,
  `obs_approved` tinyint(1) NOT NULL DEFAULT 0,
  `obs_secretid` varchar(60) COLLATE utf8_bin NOT NULL,
  `obs_complete` tinyint(1) NOT NULL DEFAULT 0,
  `obs_group` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `obs_groups`
--
ALTER TABLE `obs_groups`
  ADD PRIMARY KEY (`group_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Index pour la table `obs_list`
--
ALTER TABLE `obs_list`
  ADD PRIMARY KEY (`obs_id`),
  ADD KEY `token` (`obs_token`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `obs_groups`
--
ALTER TABLE `obs_groups`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;
--
-- AUTO_INCREMENT pour la table `obs_list`
--
ALTER TABLE `obs_list`
  MODIFY `obs_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=450;COMMIT;


 INSERT INTO `obs_list` (`obs_id`, `obs_coordinates_lat`, `obs_coordinates_lon`, `obs_comment`, `obs_categorie`, `obs_token`, `obs_time`, `obs_status`,`obs_approved`) VALUES
(19, '43.60063364932313', '3.9004433155059948', 'Parking sur le trottoir ', 1, 'ttueacArvsHCA81zNHD4tN8KvVj63l', 1547298289, 0,1);
