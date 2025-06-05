<?php
error_reporting(0);
date_default_timezone_set('Asia/Kolkata');

$json_url = 'secure/TP.json';
$json_content = file_get_contents($json_url);

if ($json_content === false || empty($json_content)) {
    echo 'Error: Could not read json';
    exit;
}

$data = json_decode($json_content, true);
if ($data === null) {
    echo 'Error: Invalid JSON format';
    exit;
}

if (!is_array($data)) {
    echo 'Error: JSON data is not an array';
    exit;
}

// EPG Guide
$m3uContent = "#EXTM3U\n";
$m3uContent .= "x-tvg-url=\"https://avkb.short.gy/epg.xml.gz\"\n\n";

// Get items by $data
foreach ($data as $channel) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');

    // Extract channel data
    $channel_id = $channel['channel_id'] ?? '';
    $channel_logo = $channel['channel_logo'] ?? '';
    $channel_genre = $channel['channel_genre'] ?? '';
    $channel_name = $channel['channel_name'] ?? '';
    $channel_url = $channel['channel_url'] ?? '';  

    if (empty($channel_id) || empty($channel_name) || empty($channel_url)) {
        continue;  // Skip if missing essential data
    }

// license url widewine & manifest url
    $license_url = "{$protocol}://{$_SERVER['HTTP_HOST']}{$base_path}/jwt.php?id=$channel_id";
    $mpd_url = "{$protocol}://{$_SERVER['HTTP_HOST']}{$base_path}/play.mpd?id=$channel_id";

// Default values
$UA = 'Shraddha/5.0';

// Check Player User Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (stripos($userAgent, 'tivimate') !== false) { // for TiviMate
    $catch = 'catchup-type="append" catchup-days="8" catchup-source="&begin={utc}&end={utcend}"';
    $HeaderM3u = '|User-Agent="Shraddha/5.0"&Origin="https://watch.tataplay.com"&Referer="https://watch.tataplay.com/"';

} elseif ($userAgent === 'Mozilla/5.0 (Windows NT 10.0; rv:78.0) Gecko/20100101 Firefox/78.0') { // for NS Player
    $catch = null;
    $HeaderM3u = '%7CUser-Agent=Shraddha/5.0&Origin=https://watch.tataplay.com/&Referer=https://watch.tataplay.com/';
    
} else { //for OTT Navigator 
    $catch = 'catchup-type="append" catchup-days="7" catchup-source="&begin={utc}&end={utcend}"';
    $HeaderM3u = '|User-Agent=Shraddha/5.0&Origin=https://watch.tataplay.com/&Referer=https://watch.tataplay.com/';
}

    // Check conditions
    if (strpos($channel_url, 'bpk-tv') !== false) {
        $m3uContent .= "#KODIPROP:inputstream.adaptive.license_type=com.widevine.alpha\n";
        $m3uContent .= "#KODIPROP:inputstream.adaptive.license_key={$license_url}\n";
        $m3uContent .= "#EXTINF:-1 tvg-id=\"ts$channel_id\" tvg-logo=\"https://mediaready.videoready.tv/tatasky-epg/image/fetch/f_auto,fl_lossy,q_auto,h_250,w_250/$channel_logo\" $catch group-title=\"$channel_genre\",$channel_name\n";
        $m3uContent .= "#EXTVLCOPT:http-user-agent=$UA\n";
        $m3uContent .= "{$mpd_url}$HeaderM3u\n\n";
    } elseif (strpos($channel_url, '.m3u8') !== false) {
        $m3uContent .= "#EXTINF:-1 tvg-id=\"ts$channel_id\" tvg-logo=\"https://mediaready.videoready.tv/tatasky-epg/image/fetch/f_auto,fl_lossy,q_auto,h_250,w_250/$channel_logo\" $catch group-title=\"$channel_genre\",$channel_name\n";
        $m3uContent .= "#EXTVLCOPT:http-user-agent=$UA\n";
        $m3uContent .= "{$channel_url}\n\n";
    } elseif (strpos($channel_url, 'tatasky') !== false) {
        $m3uContent .= "#KODIPROP:inputstream.adaptive.license_type=com.widevine.alpha\n";
        $m3uContent .= "#KODIPROP:inputstream.adaptive.license_key={$license_url}\n";
        $m3uContent .= "#EXTINF:-1 tvg-id=\"ts$channel_id\" tvg-logo=\"https://mediaready.videoready.tv/tatasky-epg/image/fetch/f_auto,fl_lossy,q_auto,h_250,w_250/$channel_logo\" $catch group-title=\"$channel_genre\",$channel_name\n";
        $m3uContent .= "#EXTVLCOPT:http-user-agent=$UA\n";
        $m3uContent .= "{$channel_url}\n\n";
    }
}

header('Content-Type: text/plain');
echo $m3uContent;
exit;
?>