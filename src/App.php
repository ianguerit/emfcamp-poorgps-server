<?php
namespace Emf;

use Pdo;
use Dotenv\Dotenv;
use Phpfastcache\Helper\Psr16Adapter;


class App
{

    private $db;
    private $cache;

    public function __construct()
    {
        $root = __DIR__.'/../';

        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();
        $dsn = $_ENV['DB_DSN'];
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASS'];

        $this->db = new Pdo($dsn, $username, $password);
        $defaultDriver = 'Files';
        $this->cache = new Psr16Adapter($defaultDriver);
    }

    private function getDatabase()
    {
        return $this->db;
    }

    private function getCache()
    {
        return $this->cache;
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

    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earthRadius * $c; // returns distance in meters
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
        // age_seconds is how old the GPS location was at the time of recording
        $sql = "
            SELECT 
                n.bssid,
                ST_X(l.coordinates) AS longitude,
                ST_Y(l.coordinates) AS latitude
            FROM field_network n
            JOIN field_network_location n_l ON n.id = n_l.field_network_id
            JOIN field_location l ON n_l.field_location_id = l.id
            WHERE n.bssid IN ($placeholders) AND hotspot = 0 AND age_seconds < 500
        ";

        $db = $this->getDatabase();

        $stmt = $db->prepare($sql);
        $stmt->execute($bssids);
        $savedLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($savedLocations)) {
            // None of these networks have been mapped yet
            return [
                'status' => 'not-found',
                'detail' => 'None of the provided network(s) have been mapped yet, unable to estimate location'
            ];
        }

        $totalWeight = 0;
        $weightedLat = 0;
        $weightedLng = 0;
        $calculatedNodes = [];

        // Constants for Distance Estimation
        $measuredPower = -40; // RSSI at 1 meter
        $pathLossExponent = 3.5; // Indoor environment with obstructions

        // Pass 1: Find the Weighted Centroid (Location)
        foreach ($savedLocations as $loc) {
            $bssid = $loc['bssid'];
            $rssi = $rssiMap[$bssid] ?? -100;

            $weight = pow(10, $rssi / 20);
            $weightedLat += $loc['latitude'] * $weight;
            $weightedLng += $loc['longitude'] * $weight;
            $totalWeight += $weight;

            // Estimate distance to this specific router in meters
            $estimatedDistance = pow(10, ($measuredPower - $rssi) / (10 * $pathLossExponent));

            $calculatedNodes[] = [
                'lat' => $loc['latitude'],
                'lng' => $loc['longitude'],
                'weight' => $weight,
                'est_dist' => $estimatedDistance
            ];
        }

        $estLat = $weightedLat / $totalWeight;
        $estLng = $weightedLng / $totalWeight;

        // Pass 2: Calculate Accuracy (Weighted Deviation + Proximity Factor)
        $weightedDistanceSum = 0;
        foreach ($calculatedNodes as $node) {
            // How far is the computed center from this router's known position?
            $distFromCenter = $this->haversineDistance($estLat, $estLng, $node['lat'], $node['lng']);

            // Weight the deviation
            $weightedDistanceSum += ($distFromCenter + $node['est_dist']) * $node['weight'];
        }

        // Weighted average error radius
        $accuracyMeters = $weightedDistanceSum / $totalWeight;

        // Fallback: If only 1 router is found, we can't calculate a geometric spread.
        // The accuracy is simply our estimated distance to that single router.
        if (count($calculatedNodes) === 1) {
            $accuracyMeters = $calculatedNodes[0]['est_dist'];
        }

        // Sanity Cap: Ensure the accuracy radius reflects physical limitations
        // (e.g., Wi-Fi signals rarely drop below 2 meters accuracy or exceed 150 meters functionally)
        $accuracyMeters = max(3.0, min($accuracyMeters, 150.0));

        if($accuracyMeters < 150) {
            
            $villages = $this->getClosestVillages($estLng, $estLat);

            return [
                'status' => 'estimate',
                'detail' => 'Estimated location',
                'latitude'        => $estLat,
                'longitude'       => $estLng,
                'accuracy_meters' => round($accuracyMeters, 2),
                'local_villages' => $villages
            ];

        } else {

            // 
            return [
                'status' => 'not-found',
                'detail' => 'Not enough signal strength / conflicting data, unable to be confident with a match, unable to estimate location'
            ];

        }
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
            WHERE hotspot = 0 AND age_seconds < 500"
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

    private function getClosestVillages($long, $lat)
    {
        $this->loadVillages();


        $db = $this->getDatabase();

        $res = $db->prepare('
SELECT 
    v.*,
    ST_X(vl.coordinates) AS longitude,
    ST_Y(vl.coordinates) AS latitude,
    ROUND(ST_Distance_Sphere(vl.coordinates, POINT(:long, :lat)), 2) AS distance
FROM field_village_location vl
JOIN field_village v ON vl.field_village_id = v.id
ORDER BY distance ASC
LIMIT 5
        ');
        $res->execute([
            'long' => $long,
            'lat' => $lat
        ]);
        
        $local_villages = [];
        while($row = $res->fetch()) {
            $local_villages[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'url' => $row['url'],
                'external_url'=> $row['external_url'],
                'descriptipn' => $row['description'],
                'num_members' => $row['num_members'],
                'longitude'=> $row['longitude'],
                'latitude' => $row['latitude'],
                'distance' => $row['distance']
            ];
        }

        return $local_villages;
    }

    private function loadVillages()
    {
        $db = $this->getDatabase();
       
        $res = $db->prepare('SELECT COUNT(*) as total FROM field_village');
        $res->execute();
        $row = $res->fetch();

        if ($row['total'] == 0){
            
            $villages = json_decode(file_get_contents('https://www.emfcamp.org/api/villages'), true);

            $res_village = $db->prepare(
                'INSERT INTO field_village
                (id, name, url, external_url, description, num_members )
                VALUES
                (:id, :name, :url, :external_url, :description, :num_members)'
            );
            $res_location = $db->prepare(
                'INSERT INTO field_village_location
                (field_village_id, coordinates)
                VALUES
                (:field_village_id, POINT(:long,  :lat))'
            );

            foreach ($villages as $village) {
                $res_village->execute([
                    'id' => $village['id'],
                    'name' => $village['name'],
                    'url' => $village['url'],
                    'external_url' => $village['external_url'],
                    'description' => $village['description'],
                    'num_members' => $village['num_members']
                ]);
                if(isset($village['location']) && isset($village['location']['coordinates'])) {
                    $res_location->execute([
                        'field_village_id' => $village['id'],
                        'long' => $village['location']['coordinates'][0],
                        'lat' => $village['location']['coordinates'][1]
                    ]);
                }
            }
        }

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
