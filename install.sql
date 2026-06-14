CREATE TABLE `field_location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(100) DEFAULT NULL,
  `coordinates` point NOT NULL,
  `accuracy_meters` float DEFAULT NULL,
  `age_seconds` int(11) DEFAULT NULL,
  `created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  SPATIAL KEY `coordinates` (`coordinates`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci;

CREATE TABLE `field_network` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` int(11) DEFAULT NULL,
  `hidden` int(1) unsigned DEFAULT NULL,
  `ssid` varchar(255) DEFAULT NULL,
  `bssid` varchar(100) DEFAULT NULL,
  `security` int(11) DEFAULT NULL,
  `created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bssid` (`bssid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci;

CREATE TABLE `field_network_location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `field_network_id` int(11) DEFAULT NULL,
  `field_location_id` int(11) DEFAULT NULL,
  `rssi` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci;
