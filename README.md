# Poor GPS (server)
Pairs with the proof of concept badge app (see companion repo).

Provides an API for getting estimated GPS coordinates based on current WiFi access point / local WiFi network stations.

Calibrated by collecting data with the badge app paired with an Android phone (see /calibrate) or potentially even the GPS Hexpansion.


## Approach
Tried to keep this as simple and approachable as possible.

I have 'vibe coded' a few of the pages (e.g. calibrate) for speed - but either way not precious on how things are currently coded.


## Key pages

### Index page `/`
Shows summary of data collected (needs lots of work to make this scalable).

### Scanner `/scanner`
This is the companion page for collecting callbration data.

Use on an Android phone (or something else that supports web based BLE APIs) to pair with an ESP32-S3 (and eventually the badge) to help collect callibrating data.

### Test `/test`
Simple test page

## API End points
Currently these are all unauthenticated

### List of all data `GET /api/networks`
*Warning* Not optimised, used by the index page

### Provide calibration data `POST /api/calibrate` 

Request body should contain the following

    {
        "device_id": "Unique device ID, recommend MAC address",
        "gps": {
            "accuracy": 5.0,
            "latitude": 52.258124,
            "longitude": -0.906367,
            "age_seconds": 690
        },
        "networks": [
            {
                "channel": 11,
                "hidden": false,
                "ssid": "SKYS8T1B",
                "bssid": "00:a3:88:d4:12:42",
                "rssi": -57,
                "security": 3
            }
        ]
    }

### Delete all device data `POST /api/device/delete`
Delete all data associated with a device id

Specify the device id as either POST data or in a JSON body

    {
        "device_id": "delete device id"
    }

### Get an esimtation of where you are `POST /api/whereami` 

Request body should contain the following

    {
        "device_id": "Unique device ID, recommend MAC address",
        "networks": [
            {
                "channel": 11,
                "hidden": false,
                "ssid": "SKYS8T1B",
                "bssid": "00:a3:88:d4:12:42",
                "rssi": -57,
                "security": 3
            }
        ]
    }

The response body will be as follows

    {
        "latitude": 52.258124,
        "longitude": -0.906367
    }



## Hosting 
I am happy to host for EMF 2026 on https://poorgps.emfcamp.illumo.dev

Feel free to use the APIs for other applications.

I'll do my best to moderate any bad data, but please don't abuse this service.

Note I'm also supporting HTTP access, as not sure how the badge will cope with HTTPS yet.


## Hosting Requirements
I haven't dockerised this yet, so if you want to self-host your own version, you'd need to run this on a Linux Apache MariaDB Php (LAMP) based server.

Use composer to install dependencies `composer install`

Copy and update example.env to .env

Import the database tables
