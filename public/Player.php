<?php
error_reporting(0);  
header("Content-Type: text/html");
date_default_timezone_set('Asia/Kolkata');

// Get `id` parameter from the query string
$id = $_GET['id'] ?? null;
if ($id === null) {
    die('<h1>Error: Missing "id" parameter</h1>');
}

// Get protocol (http or https)  
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';  
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

// Fetch and decode JSON data
function fetchJson($url) {
    $json = @file_get_contents($url);
    return $json !== false ? json_decode($json, true) : false;
}

$JsonFile = fetchJson('secure/TP.json');
if ($JsonFile === false) {
    die('<h1>Error fetching JSON file</h1>');
}

// Find the channel data by ID
$channelData = null;
foreach ($JsonFile as $channel) {
    if ($channel['channel_id'] == $id) {
        $channelData = $channel;
        break;
    }
}

if (!$channelData) {
    die('<h1>Error: Channel data not found</h1>');
}

$manifestUrl = $channelData['channel_url'];

// Fetch HMAC
$hmacUrl = "$protocol://{$_SERVER['HTTP_HOST']}{$base_path}/hmac.php?id=" . urlencode($id);
$hmacResponse = @file_get_contents($hmacUrl);
if ($hmacResponse === false) {
    die('<h1>Error: Failed to Load HMAC</h1>');
}

$hmacData = json_decode($hmacResponse, true);
$hmac = $hmacData['hmac']['hdntl']['value'] ?? null;
$userAgent = $hmacData['userAgent'] ?? "Shraddha/5.0";

if (!$hmac) {
    die('<h1>Error: hdntl value not found</h1>');
}

// Append HMAC to manifest URL
$manifestUrl .= "?$hmac";

// Fetch Widevine Data
$widevineUrl = "$protocol://{$_SERVER['HTTP_HOST']}{$base_path}/widevine.php?id=" . urlencode($id);
$wvResponse = @file_get_contents($widevineUrl);
if ($wvResponse === false) {
    die('<h1>Error: Failed to fetch Widevine data</h1>');
}

$wvData = json_decode($wvResponse, true);
if (!isset($wvData['widevine'], $wvData['jwt'])) {
    die('<h1>Error: Invalid response from Widevine</h1>');
}

$licenceURL = $wvData['widevine'] . '&ls_session=' . urlencode($wvData['jwt']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JW Player</title>
    <style>
        body {
            margin: 0;
        }
        .jwplayer {
            position: absolute !important;
        }
        .jwplayer.jw-flag-aspect-mode {
            min-height: 100%;
            max-height: 100%;
        }
    </style>
</head>
<body>
    <script src="https://cdn.jwplayer.com/libraries/SAHhwvZq.js"></script>

    <div id="jwplayerDiv"></div>
    <script type="text/javascript">
        jwplayer("jwplayerDiv").setup({
       skin: {
       name: "Denver",
       active: "RED",
       inactive: "white",
       background: "black"
    },
            autostart: true,
            preload: "none",
            repeat: true,
            volume: 100,
            mute: false,
            stretching: "exactfit",
            width: "100%",
            cast: {},
            file: "<?= $manifestUrl ?>",
            type: "dash",
            drm: {
                widevine: {
                    url: "<?= $licenceURL ?>",
                    headers: [
                        {
                            "name": "User-Agent",
                            "value": "<?= $userAgent ?>"
                        }
                    ]
                }
            }
        });
    </script>
</body>
</html>