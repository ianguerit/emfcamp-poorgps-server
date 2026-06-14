<?php
namespace Emf;

use Pdo;
use Dotenv\Dotenv;


class App
{

    private $db;

    public function __construct()
    {
        $root = __DIR__.'/../';

        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();
        $dsn = $_ENV['DB_DSN'];
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASS'];

        $this->db = new Pdo($dsn, $username, $password);
    }

    private function getDatabase()
    {
        return $this->db;
    }

    public function recordFieldData(array $data)
    {
        $gps = $data['gps'];
        $device_id = $data['device_id'];
        $networks = $data['networks'];

        $db = $this->getDatabase();

        // check if we've got GPS data, if not abort
        // "gps": {"accuracy": 5.0, "latitude": 52.258124, "longitude": -0.906367, "age_seconds": 690}
        if (empty($gps['latitude']) || empty($gps['longitude'])) {
            return false;
        }

        // record the location
        //  id int auto_increment primary key, device_id varchar(100), coordinates POINT NOT NULL, accuracy_meters float, age_seconds int, created datetime default NOW(), SPATIAL INDEX(coordinates)
        $res = $db->prepare(
            "INSERT INTO field_location (device_id, coordinates, accuracy_meters, age_seconds)
            VALUES(:device_id, POINT(:long,  :lat), :accuracy, :age)"
        );
        $values = [
            'device_id' => $device_id,
            'long'=> $gps['longitude'],
            'lat' => $gps['latitude'],
            'accuracy' => $gps['accuracy'],
            'age' => $gps['age_seconds']
        ];
        $res->execute($values);
        $location_id = $db->lastInsertId();

        $res = $db->prepare(
            "INSERT INTO field_network_location (field_network_id, field_location_id, rssi) VALUES(:network_id, :location_id, :rssi)"
        );
        foreach ($networks as $network) {

            $network_id = $this->getNetwork($network);
            $values = [
                'network_id' => $network_id,
                'location_id' => $location_id,
                'rssi' => $network['rssi']
            ];
            $res->execute($values);
        }
    }

    private function getNetwork(array $network): int
    {
        $db = $this->getDatabase();

        // check for existing network
        $res = $db->prepare(
            "SELECT id FROM field_network WHERE bssid = :bssid"
        );
        $res->execute(['bssid' => $network['bssid']]);
        while($row = $res->fetch()) {
            return $row['id'];
        }

        $res = $db->prepare(
            "INSERT INTO field_network (channel, hidden, ssid, bssid, security) VALUES(:channel, :hidden, :ssid, :bssid, :security)"
        );
        $values = [
            'channel' => $network['channel'],
            'hidden' => (int)$network['hidden'],
            'ssid'=> $network['ssid'],
            'bssid'=> $network['bssid'],
            'security'=> $network['security']
        ];
        $res->execute($values);

        return $db->lastInsertId();
    }


    /**
     * Estimates GPS location using Weighted Centroid Localization
     * * @param PDO $db MySQL PDO Instance
     * @param array $observedNetworks Array of ['bssid' => '...', 'rssi' => -65]
     * @return array|null ['lat' => X, 'lng' => Y] or null if no match
     */
    public function estimateLocation(array $observedNetworks): ?array 
    {
        if (empty($observedNetworks)) {
            return null;
        }

        // 1. Extract BSSIDs to fetch known locations from database
        $bssids = array_column($observedNetworks, 'bssid');
        
        // Map RSSI by BSSID for quick lookup during calculation
        $rssiMap = array_column($observedNetworks, 'rssi', 'bssid');

        // 2. Prepare the IN clause dynamically
        $placeholders = implode(',', array_fill(0, count($bssids), '?'));
        
        // We use ST_X() and ST_Y() to extract Long/Lat from the SPATIAL point column
        $sql = "
            SELECT 
                n.bssid,
                ST_X(l.coordinates) AS longitude,
                ST_Y(l.coordinates) AS latitude
            FROM field_network n
            JOIN field_network_location n_l ON n.id = n_l.field_network_id
            JOIN field_location l ON n_l.field_location_id = l.id
            WHERE n.bssid IN ($placeholders) AND hotspot = 0
        ";

        $db = $this->getDatabase();

        $stmt = $db->prepare($sql);
        $stmt->execute($bssids);
        $savedLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($savedLocations)) {
            return null; // None of these networks have been mapped yet
        }

        $totalWeight = 0;
        $weightedLat = 0;
        $weightedLng = 0;

        // 3. Apply the WCL algorithm
        foreach ($savedLocations as $loc) {
            $bssid = $loc['bssid'];
            $rssi = $rssiMap[$bssid] ?? -100; // Fallback to weak signal if missing

            // Convert logarithmic RSSI to a linear weight 
            // (Stronger signal = drastically higher weight)
            $weight = pow(10, $rssi / 20);

            $weightedLat += $loc['latitude'] * $weight;
            $weightedLng += $loc['longitude'] * $weight;
            $totalWeight += $weight;
        }

        // 4. Calculate final weighted average
        if ($totalWeight > 0) {
            return [
                'latitude'  => $weightedLat / $totalWeight,
                'longitude' => $weightedLng / $totalWeight
            ];
        }

        return null;
    }

    public function getMapData()
    {
        $db = $this->getDatabase();
        
        $out = [];
        $res = $db->prepare(
            "SELECT
                ssid,
                bssid,
                rssi,
                ST_X(coordinates) AS lng,
                ST_Y(coordinates) AS lat
            FROM field_network_location
            LEFT JOIN field_network ON field_network_id = field_network.id
            LEFT JOIN field_location ON field_location_id = field_location.id
            WHERE hotspot = 0"
        );
        $res->execute();
        while($row = $res->fetch()) {
            $out[] = [
                'ssid' => $row['ssid'],
                'bssid' => $row['bssid'],
                'rssi' => $row['rssi'],
                'lat' => $row['lat'],
                'lng' => $row['lng']
            ];
        }

        return $out;
    }

    public function deleteDevice($device_id)
    {
        // clear all a device's data
        $db = $this->getDatabase();

        $res = $db->prepare(
            "DELETE FROM field_location WHERE device_id = :device_id"
        );
        $res->execute(['device_id' => $device_id]);

        $this->cleanNetworks();
    }

    public function cleanNetworks()
    {
        // clean networks that aren't linked to any locations
        $db = $this->getDatabase();

        $res = $db->prepare(
            "DELETE n FROM `field_network` n
            LEFT JOIN `field_network_location` fnl ON n.`id` = fnl.`field_network_id`
            WHERE fnl.`field_network_id` IS NULL"
        );
        $res->execute();

    }
}
