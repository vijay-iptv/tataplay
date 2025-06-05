<?php
error_reporting(0);
header("Content-Type: application/json");
// Get `id` parameter
$id = $_GET['id'] ?? exit(json_encode(['error' => 'Missing id!']));

// Define cache file path
$cacheFile = "cache/pssh_$id";

// Check if the cache exists
if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (time() - filemtime($cacheFile) < 580) {
        echo json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Path to the JSON file
$mpdLinkUrl = __DIR__ . "/secure/TP.json"; // Use absolute path

// Check if file exists
if (!file_exists($mpdLinkUrl)) {
    die("Error: JSON file not found at: $mpdLinkUrl");
}

// Fetch and decode the JSON data
$mpdResponse = file_get_contents($mpdLinkUrl);
if ($mpdResponse === false) {
    die("Error: Unable to fetch data from file.");
}

$responseData = json_decode($mpdResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: JSON decoding failed - " . json_last_error_msg());
}

// Find the channel data by ID
$channelData = null;
foreach ($responseData as $channel) {
    if (isset($channel['channel_id']) && $channel['channel_id'] == $id) {
        $channelData = $channel;
        break;
    }
}

// If channel data is not found
if ($channelData === null) {
    die("Error: Channel with ID $id not found.");
}

// Retrieve `channel_url` for the specified `channel_id`
$manifestUrl = $channelData['channel_url'];

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

// Fetch the HMAC value from the API
$hmacUrl = "{$protocol}://{$_SERVER['HTTP_HOST']}{$base_path}/hmac.php?id=" . urlencode($id);
$hmacResponse = @file_get_contents($hmacUrl);
if ($hmacResponse === false) {
    die("Error: Failed to retrieve HMAC");
}

$hmacData = json_decode($hmacResponse, true);
$hdneaRaw = $hmacData['hmac']['hdnea']['value'] ?? null;
if (!$hdneaRaw) {
    die("Error: hdnea value not found in HMAC response");
}

// Remove the "?" from the HMAC value
$hmac = ltrim($hdneaRaw, '?');

// manifest url replace with
$manifestUrl = str_replace("bpaita", "bpaicatchupta", $manifestUrl);

// Generate time parameters based on conditions
function generateTimeParameters($id, $beginParam = null, $endParam = null) {
    $currentTimestamp = time();
    
    // Check if begin & end are provided in the URL
    if (!empty($beginParam) && !empty($endParam)) {
        return [
            'begin' => $beginParam,
            'end' => $endParam
        ];
    }

    // IDs that require a 14-minute window instead of 30 minutes
    $specialIds = ['24', '78', '503', '123', '175', '727', '64', '251', 
                   '248', '249', '515', '254', '250', '636', '1288', 
                   '257', '675', '810', '694', '1287'];

    // Set time window based on ID
    $beginTimestamp = in_array($id, $specialIds) ? 
                      $currentTimestamp - (1 * 60) :  // 14 minutes ago for special IDs
                      $currentTimestamp - (30 * 60);  // 30 minutes ago for others

    $endTimestamp = $currentTimestamp + (4 * 60 * 60); // 4 hours ahead

    return [
        'begin' => gmdate('Ymd\THis', $beginTimestamp),
        'end' => gmdate('Ymd\THis', $endTimestamp)
    ];
}

// Get begin & end from URL if provided
$beginParam = $_GET['begin'] ?? null;
$endParam = $_GET['end'] ?? null;

// Generate time parameters
$timeParams = generateTimeParameters($id, $beginParam, $endParam);

// Manifest URL with timestamp & HMAC
$mpd = $manifestUrl . "?begin=" . $timeParams['begin'] . "&end=" . $timeParams['end'] . "&" . $hmac;


// Function to download video content
function download_video($videoUrl, $userAgent) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $videoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

    $videoContent = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $error_msg);
    }

    curl_close($ch);
    return $videoContent;
}

// Process the `mpd_link`
$parsedUrl = parse_url($mpd);
$pathInfo = pathinfo($parsedUrl['path']);
$baseVideoUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $pathInfo['dirname'];
$query_params = [];
parse_str($parsedUrl['query'], $query_params);
$hdnea = '?hdnea=' . ($query_params['hdnea'] ?? null);
$userAgent = 'Shraddha/5.0';

// Capture cookies and fetch PSSH/KID data
function capture_cookies($mpdUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $mpdUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    curl_close($ch);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = [];
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    return ['cookies' => $cookies, 'body' => $body];
}

$res = capture_cookies($mpd);
$hdntl = 'hdntl=' . $res['cookies']['hdntl'] ?? '';
$content = $res['body'];
$xml = new SimpleXMLElement($content);

foreach ($xml->Period->AdaptationSet as $adaptationSet) {
    if ((string)$adaptationSet['contentType'] === 'video') {
        foreach ($adaptationSet->Representation as $representation) {
            if (isset($representation->SegmentTemplate)) {
                $media = (string)$representation->SegmentTemplate['media'];
                $startNumber = (int) $representation->SegmentTemplate['startNumber'] ?? 0;
                $repeatCount = (int) $representation->SegmentTemplate->SegmentTimeline->S['r'] ?? 0;
                $modifiedStartNumber = $startNumber + $repeatCount;
                $mediaFileName = str_replace(['$RepresentationID$', '$Number$'], [(string)$representation['id'], $modifiedStartNumber], $media);
                $videoUrl = $baseVideoUrl . '/dash/' . $mediaFileName . $hdnea;

                try {
                    $videoContent = download_video($videoUrl, $userAgent);
                } catch (Exception $e) {
                    die('Error: ' . $e->getMessage());
                }

                if ($videoContent === false) {
                    die("Error: Failed to download video content.");
                }

                $hexVideoContent = bin2hex($videoContent);
                $psshMarker = "70737368";
                $pos = strpos($hexVideoContent, $psshMarker);

                if ($pos !== false) {
                    $headerSizeHex = substr($hexVideoContent, $pos - 8, 8);
                    $headerSize = hexdec($headerSizeHex);
                    $psshHex = substr($hexVideoContent, $pos - 8, $headerSize * 2);
                    $kidHex = substr($psshHex, 68, 32);
                    $newPsshHex = "000000327073736800000000edef8ba979d64acea3c827dcd51d21ed000000121210" . $kidHex;
                    $pssh = base64_encode(hex2bin($newPsshHex));
                    $kid = substr($kidHex, 0, 8) . "-" . substr($kidHex, 8, 4) . "-" . substr($kidHex, 12, 4) . "-" . substr($kidHex, 16, 4) . "-" . substr($kidHex, 20);
                }
                break 2;
            }
        }
    }
}

// Prepare and output the final JSON data
$data = [
    "created_by" => "Denver1769",
    "pssh" => $pssh ?? null,
    "kid" => $kid ?? null,
    "hdntl" => $hdntl ?? null
];

// Cache the data
file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>