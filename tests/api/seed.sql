-- Test data for the API contract tests (tests/api/contract.py).
-- Loaded on top of a freshly migrated database. Times are fixed so that responses
-- are identical from one run to the next.
SET SESSION sql_mode = '';

UPDATE obs_config SET config_value = 'http' WHERE config_param = 'vigilo_http_proto';
-- vigilo_urlbase is set by the runner (host:port of the instance under test)
UPDATE obs_config SET config_value = '1' WHERE config_param = 'vigilo_shownonapproved';

INSERT INTO obs_scopes (scope_id, scope_name, scope_display_name, scope_department,
  scope_coordinate_lat_min, scope_coordinate_lat_max, scope_coordinate_lon_min, scope_coordinate_lon_max,
  scope_map_center_string, scope_map_zoom, scope_contact_email, scope_sharing_content_text,
  scope_twitter, scope_twitteraccountid, scope_twittercontent, scope_umap_url, scope_nominatim_urlbase)
VALUES
  (1, '99_testville', 'Testville', 99, '43.5', '43.7', '3.8', '4.0', '43.6, 3.9', 14,
   'contact@testville.example', 'Partagez [URL]', 'testville', 0, '', 'https://umap.example/testville',
   'https://nominatim.openstreetmap.org'),
  (2, '98_autre', 'Autre', 98, '44.0', '44.2', '4.0', '4.2', '44.1, 4.1', 13,
   '', '', '', 0, '', '', 'https://nominatim.openstreetmap.org');

INSERT INTO obs_cities (city_id, city_scope, city_name, city_postcode, city_area, city_population, city_website) VALUES
  (1, 1, 'Testville', 99000, 12.5, 25000, 'https://testville.example'),
  (2, 1, 'Saint-Exemple', 99100, 3.2, 4000, '');

INSERT INTO obs_roles (role_id, role_key, role_name, role_owner, role_login, role_password, role_city) VALUES
  (1, 'ADMINKEY0123456789', 'admin', 'Admin Test', 'admin', '', ''),
  (2, 'MODKEY0123456789', 'moderator', 'Modo Test', 'modo', '', ''),
  (3, 'STAFFKEY0123456789', 'citystaff', 'Staff Test', 'staff', '', '["Testville"]');

INSERT INTO obs_list (obs_id, obs_scope, obs_city, obs_cityname, obs_coordinates_lat, obs_coordinates_lon,
  obs_address_string, obs_comment, obs_explanation, obs_categorie, obs_token, obs_time, obs_status,
  obs_app_version, obs_approved, obs_secretid, obs_complete) VALUES
  (1, '99_testville', 1, '', '43.6000', '3.9000', 'Rue de la Gare', 'Voiture "garée", trottoir é', 'Tous les matins', 2, 'TOKA0001', 1600000000, 0, '1', 1, 'SECRET0001', 1),
  (2, '99_testville', 0, 'Autreville', '43.6100', '3.9100', 'Avenue du Port', 'En attente', '', 3, 'TOKA0002', 1610000000, 0, '1', 0, 'SECRET0002', 1),
  (3, '99_testville', 0, '', '43.6003', '3.9003', 'Rue des Lilas, Bourgade', 'Proche de la gare', '', 2, 'TOKA0003', 1620000000, 0, '1', 1, 'SECRET0003', 1),
  (4, '99_testville', 2, '', '43.6200', '3.9200', 'Place Centrale', 'Refusée', '', 4, 'TOKA0004', 1630000000, 0, '1', 2, 'SECRET0004', 1),
  (5, '99_testville', 1, '', '43.6300', '3.9300', 'Rue Incomplete', 'Sans photo', '', 2, 'TOKA0005', 1640000000, 0, '1', 1, 'SECRET0005', 0),
  (6, '98_autre', 0, 'Ailleurs', '44.1000', '4.1000', 'Chemin Vert', 'Autre scope', '', 5, 'TOKA0006', 1650000000, 0, '1', 1, 'SECRET0006', 1),
  (7, '99_testville', 1, '', '43.6400', '3.9400', 'Boulevard Est', 'Résolue', '', 2, 'TOKA0007', 1660000000, 0, '1', 1, 'SECRET0007', 1),
  (8, '99_testville', 1, '', '43.6500', '3.9500', 'Impasse Ouest', 'A supprimer', '', 2, 'TOKA0008', 1670000000, 0, '1', 1, 'SECRET0008', 1);

INSERT INTO obs_resolutions (resolution_id, resolution_token, resolution_secretid, resolution_app_version,
  resolution_comment, resolution_time, resolution_status, resolution_withphoto, resolution_complete) VALUES
  (1, 'R_RES00001', 'RSECRET0001', 1, 'Réparé', 1665000000, 1, 1, 1),
  (2, 'R_RES00002', 'RSECRET0002', 1, 'Signalé résolu', 1625000000, 4, 0, 1);

INSERT INTO obs_resolutions_tokens (restok_resolutionid, restok_observationid) VALUES
  (1, 7),
  (2, 3);
