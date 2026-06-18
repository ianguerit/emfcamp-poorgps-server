CREATE TABLE `field_location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(100) DEFAULT NULL,
  `coordinates` point NOT NULL,
  `accuracy_meters` float DEFAULT NULL,
  `age_seconds` int(11) DEFAULT NULL,
  `created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  SPATIAL KEY `coordinates` (`coordinates`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `field_network` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` int(11) DEFAULT NULL,
  `hidden` int(1) unsigned DEFAULT 0,
  `ssid` varchar(255) DEFAULT NULL,
  `bssid` varchar(100) DEFAULT NULL,
  `security` int(11) DEFAULT NULL,
  `hotspot` int(1) unsigned DEFAULT 0,
  `created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bssid` (`bssid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `field_network_location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_network_id` int(11) DEFAULT NULL,
  `field_location_id` int(11) DEFAULT NULL,
  `rssi` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_field_location_idx` (`field_location_id`),
  KEY `fk_fnl_field_network` (`field_network_id`),
  CONSTRAINT `fk_fnl_field_location` FOREIGN KEY (`field_location_id`) REFERENCES `field_location` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fnl_field_network` FOREIGN KEY (`field_network_id`) REFERENCES `field_network` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `field_village` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `external_url` varchar(255) DEFAULT NULL,
  `description` mediumtext DEFAULT NULL,
  `num_members` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `field_village_location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_village_id` int(11) DEFAULT NULL,
  `coordinates` point NOT NULL,
  PRIMARY KEY (`id`),
  SPATIAL KEY `coordinates` (`coordinates`),
  KEY `fk_fnl_field_village` (`field_village_id`),
  CONSTRAINT `fk_fnl_field_village` FOREIGN KEY (`field_village_id`) REFERENCES `field_village` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb4_unicode_ci;
