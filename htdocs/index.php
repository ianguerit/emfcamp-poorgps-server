<?php
define('DIR_ROOT', __DIR__.'/../');
define('DIR_TEMPLATES', DIR_ROOT.'templates/');

require_once(DIR_ROOT.'vendor/autoload.php');


$app = new Emf\App();

$router = new AltoRouter();

// Default page, shows overview of data
$router->map('GET', '/', function() use ($app) {
    require(DIR_TEMPLATES.'map.html');
});

// Used by default page, lists networks
$router->map('GET', '/api/networks', function() use ($app) {
    echo json_encode($app->getMapData(), JSON_PRETTY_PRINT);
});

// Used as companion app to pair with badge app to share GPS
$router->map('GET', '/calibrate', function() use ($app) {
    require(DIR_TEMPLATES.'calibrate.html');
});

// Used by badge app with scanner to send calibration data
$router->map('POST', '/api/calibrate', function() use ($app) {
    $data = json_decode(file_get_contents('php://input'), true);
    $app->recordFieldData($data);
    $location = $app->estimateLocation($data['networks']);
    if ($location) {
        echo json_encode($location, JSON_PRETTY_PRINT);
    }
});

// Used by badge app to estimate approximate location
$router->map('POST', '/api/whereami', function() use ($app) {
    $data = json_decode(file_get_contents('php://input'), true);
    $location = $app->estimateLocation($data['networks']);
    if ($location) {
        echo json_encode($location, JSON_PRETTY_PRINT);
    }
});

// Delete a device (when you want to remove your data)
$router->map('POST', '/api/device/delete', function() use ($app) {
    // traditional post data
    $device_id = $_POST['device_id'];
    if (empty($device_id)) {
        // JSON based request body
        $data = json_decode(file_get_contents('php://input'), true);
        $device_id = $data['device_id'];
    }
    if ($app->deleteDevice($device_id)) {
        http_response_code(200);
    } else {
        http_response_code(400); // bad request
    }
});

$router->map('GET', '/test', function() use ($app) {
    require(DIR_TEMPLATES.'test.html');
});

$match = $router->match();

if ($match) {
    call_user_func_array($match['target'], $match['params']);
} else {
    http_response_code(404);
}
