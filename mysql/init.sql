CREATE DATABASE IF NOT EXISTS dbname;

USE dbname;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données :  `dbname`
--

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
  `obs_complete` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

-- --------------------------------------------------------

--
-- Structure de la table `obs_roles`
--

CREATE TABLE `obs_roles` (
  `role_id` int(11) NOT NULL,
  `role_key` varchar(255) CHARACTER SET latin1 NOT NULL,
  `role_name` varchar(45) CHARACTER SET latin1 NOT NULL,
  `role_owner` varchar(255) CHARACTER SET latin1 NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin ROW_FORMAT=COMPACT;

--
-- Index pour les tables déchargées
--
--
-- Index pour la table `obs_list`
--
ALTER TABLE `obs_list`
  ADD PRIMARY KEY (`obs_id`),
  ADD KEY `token` (`obs_token`);

--
-- Index pour la table `obs_roles`
--
ALTER TABLE `obs_roles`
  ADD PRIMARY KEY (`role_id`);

--
-- AUTO_INCREMENT pour les tables déchargées
--
--
-- AUTO_INCREMENT pour la table `obs_list`
--
ALTER TABLE `obs_list`
  MODIFY `obs_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `obs_roles`
--
ALTER TABLE `obs_roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

 INSERT INTO `obs_list` (`obs_id`, `obs_coordinates_lat`, `obs_coordinates_lon`, `obs_comment`, `obs_categorie`, `obs_token`, `obs_time`, `obs_status`,`obs_approved`) VALUES
(19, '43.60063364932313', '3.9004433155059948', 'Parking sur le trottoir ', 1, 'ttueacArvsHCA81zNHD4tN8KvVj63l', 1547298289, 0,1);
