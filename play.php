<?php
$id = $_GET['id'] ?? '';
if ($id === null) {
    echo '<h1>Error:  id Parameter Missing</h1>';
    exit;
}

$catchupRequest = false;
$beginTimestamp = $endTimestamp = null;
if (isset($_GET['begin'], $_GET['end'])) {// TiviMate & Ott Navigator
    $catchupRequest = true;
    $beginTimestamp = intval($_GET['begin']);
    $endTimestamp = intval($_GET['end']);
    $beginFormatted = gmdate('Ymd\THis', $beginTimestamp);
    $endFormatted = gmdate('Ymd\THis', $endTimestamp);
}

// Fetch and decode JSON data
$JsonFile = fetchJson('secure/TP.json');
if ($JsonFile === false) {
    echo '<h1>Error fetching Json file</h1>';
    exit;
}

// Decode JSON data
$fetcherData = $JsonFile;

// Function to fetch JSON data
function fetchJson($url) {
    $json = @file_get_contents($url);
    return $json !== false ? json_decode($json, true) : false;
}

// Find the channel data by ID
$channelData = null;
foreach ($fetcherData as $channel) {
    if ($channel['channel_id'] === $id) {
        $channelData = $channel;
        break;
    }
}

if (!$channelData) {
    http_response_code(404);
    exit('<h1>data not found for channel</h1>');
}

if (!isset($channelData['is_catchup_available']) || $channelData['is_catchup_available'] === false) {
    $catchupRequest = false;
}

// Get the current protocol (http or https)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

// Construct the HMAC URL
$hmacUrl = "{$protocol}://{$_SERVER['HTTP_HOST']}" . ($base_path ? $base_path : '') . "/hmac.php?id=" . urlencode($id);

// Fetch the HMAC value
$hmacResponse = @file_get_contents($hmacUrl);
if ($hmacResponse === false) {
    http_response_code(500);
    exit('<h1>Error: Failed to Load HMAC</h1>');
}

// Decode the HMAC response
$hmacData = json_decode($hmacResponse, true);
$hmac = $hmacData['hmac']['hdntl']['value'] ?? null;
$userAgent = $hmacData['userAgent'] ?? null;

if (!$hmac) {
    http_response_code(500);
    exit('<h1>Error: hdntl value not found</h1>');
}

$manifestUrl = $channelData['channel_url'];
if (strpos($manifestUrl, 'bpaita') === false) {
    header("Location: $manifestUrl");
    exit;
}

$manifestUrl = str_replace("bpaita", "bpaicatchupta", $manifestUrl);
$baseUrl = dirname($manifestUrl);

$manifestUrl .= "?$hmac";
if ($catchupRequest) {
    $manifestUrl .= '&begin=' . $beginFormatted . '&end=' . $endFormatted;
}

// Fetch Function
function fetchContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Keep headers
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: */*', 
        'Connection: keep-alive', 
        'Origin: https://watch.tataplay.com',
        'Referer: https://watch.tataplay.com/',
        'User-Agent: Shraddha/5.0'
    ]);

    $response = curl_exec($ch);
    
    if ($response === false) {
        return json_encode(["error" => curl_error($ch)]);
    }

    // Get header size and separate headers from body
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $body = substr($response, $headerSize);
   
    return $body;
}

// fetch mpd
$originalMpdContent = fetchContent($manifestUrl);
if (!$originalMpdContent) {
    http_response_code(500);die("failed to fetch mpd");
}
$mpdContent = $originalMpdContent;

// mpd inside modification
$mpdContent = preg_replace_callback('/<SegmentTemplate\s+.*?>/', function ($matches) use ($hmac) {
    $cleaned = preg_replace('/(\$Number\$\.m4s|\$RepresentationID\$\.dash)[^"]*/', '$1', $matches[0]);
    return str_replace(['$Number$.m4s', '$RepresentationID$.dash'],['$Number$.m4s?' . $hmac, '$RepresentationID$.dash?' . $hmac], $cleaned);
}, $mpdContent);

$mpdContent = preg_replace('/<BaseURL>.*<\/BaseURL>/', "<BaseURL>$baseUrl/dash/</BaseURL>", $mpdContent);
$mpdContent = str_replace("<!-- Created with Broadpeak BkS350 Origin Packager  (version=1.12.8-28913) -->","<!-- Created by @Denver1769  (version=5.3) -->", $mpdContent);

if (strpos($mpdContent, 'pssh') === false && strpos($mpdContent, 'cenc:default_KID') === false) {

    $widevinePssh = extractWidevinePssh($mpdContent, $baseUrl, $catchupRequest);
    if ($widevinePssh === null) {
        http_response_code(500); die("Unable to extract Pssh.");
    }
    $mpdContent = preg_replace('/<BaseURL>.*<\/BaseURL>/', "<BaseURL>$baseUrl/dash/</BaseURL>", $mpdContent);

    $newContent = "<!-- Common Encryption -->\n      <ContentProtection schemeIdUri=\"urn:mpeg:dash:mp4protection:2011\" value=\"cenc\" cenc:default_KID=\"{$widevinePssh['kid']}\"/>";
 
    $mpdContent = str_replace('<ContentProtection value="cenc" schemeIdUri="urn:mpeg:dash:mp4protection:2011"/>',$newContent,$mpdContent);
 
    $pattern = '/<ContentProtection\s+schemeIdUri="(urn:[^"]+)"\s+value="Widevine"\/>/';

    $mpdContent = preg_replace_callback($pattern, function ($matches) use ($widevinePssh) {
        return "<!--Widevine-->\n      <ContentProtection schemeIdUri=\"{$matches[1]}\" value=\"Widevine\">\n        <cenc:pssh>{$widevinePssh['pssh']}</cenc:pssh>\n      </ContentProtection>";
    }, $mpdContent);
  
    $mpdContent = preg_replace('/xmlns="urn:mpeg:dash:schema:mpd:2011"/', '$0 xmlns:cenc="urn:mpe:cenc:2013"', $mpdContent);
}

// response header
header('Content-Type: application/dash+xml');
header('Content-Disposition: attachment; filename="manifest_$id.mpd"');
echo $mpdContent;
exit;

// get pash
function extractWidevinePssh(string $content, string $baseUrl, ?int $catchupRequest): ?array {
    if (($xml = @simplexml_load_string($content)) === false) return null;
    foreach ($xml->Period->AdaptationSet as $set) {
        if ((string)$set['contentType'] === 'audio') {
            foreach ($set->Representation as $rep) {
                $template = $rep->SegmentTemplate ?? null;
                if ($template) {
                    $startNumber = $catchupRequest ? (int)($template['startNumber'] ?? 0) : (int)($template['startNumber'] ?? 0) + (int)($template->SegmentTimeline->S['r'] ?? 0);
                    $media = str_replace(['$RepresentationID$', '$Number$'], [(string)$rep['id'], $startNumber], $template['media']);
                    $url = "$baseUrl/dash/$media";
                    if (($content = fetchContent($url)) != false) {
                        $hexContent = bin2hex($content);
                        return extractKid($hexContent);
                    }
                }
            }
        }
    }
    return null;
}

// get default kid
function extractKid($hexContent) {
    $psshMarker = "70737368";
    $psshOffset = strpos($hexContent, $psshMarker);
    
    if ($psshOffset !== false) {
        $headerSizeHex = substr($hexContent, $psshOffset - 8, 8);
        $headerSize = hexdec($headerSizeHex);
        $psshHex = substr($hexContent, $psshOffset - 8, $headerSize * 2);
        $kidHex = substr($psshHex, 68, 32);
        $newPsshHex = "000000327073736800000000edef8ba979d64acea3c827dcd51d21ed000000121210" . $kidHex;
        $pssh = base64_encode(hex2bin($newPsshHex));
        $kid = substr($kidHex, 0, 8) . "-" . substr($kidHex, 8, 4) . "-" . substr($kidHex, 12, 4) . "-" . substr($kidHex, 16, 4) . "-" . substr($kidHex, 20);
        
        return ['pssh' => $pssh, 'kid' => $kid];
    }
    
    return null;
}

// I'm not made this script for scammers :-( as a Developer I just want to help my user's for free; please i request don't sell it.
?>