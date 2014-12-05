<?php

require_once('DBConfig.php');

$db = new PDO("mysql:host=" . DBConfig::$host . ";charset=utf8",
    DBConfig::$user, DBConfig::$password);

$db->query("
CREATE DATABASE IF NOT EXISTS `geoselector` CHARACTER SET utf8 COLLATE utf8_general_ci;

USE geoselector;
CREATE TABLE IF NOT EXISTS region
(
	id INT NOT NULL PRIMARY KEY,
	rgid INT NOT NULL,
	name VARCHAR(255) NOT NULL,
	parent_id INT NOT NULL,
    has_metro TINYINT(1),
    has_subloc TINYINT(1)
);

CREATE TABLE IF NOT EXISTS station
(
	id INT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    region_id INT NOT NULL,
    CONSTRAINT fk_station_region FOREIGN KEY (region_id)
		REFERENCES region (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sublocality
(
	id INT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    region_id INT NOT NULL,
    CONSTRAINT fk_sublocality_region FOREIGN KEY (region_id)
		REFERENCES region (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);");

$db = null;

echo "Success\n";
