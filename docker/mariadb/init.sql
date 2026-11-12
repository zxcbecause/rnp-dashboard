-- Test database used by Codeception; the main one is created from MARIADB_DATABASE.
CREATE DATABASE IF NOT EXISTS `rnp_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `rnp_test`.* TO 'rnp'@'%';
FLUSH PRIVILEGES;
