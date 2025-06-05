<?php
include 'functions.php';

if (!logged_in()) {
    header('Location: login.php');
    exit;
}

// Read and decode credentials
$UserData = file_get_contents("secure/_sessionData");
$json = json_decode($UserData, true);

if (!$json) {
    exit(json_encode(['error' => 'Invalid or missing credentials']));
}

// Extract values correctly
$expiresInMillis = $json['data']['expiresIn']; // Timestamp in milliseconds
$expiresInSeconds = intval($expiresInMillis / 1000); // Convert to seconds
$expiresAt = date('d/m/Y h:i A', $expiresInSeconds); // Format time in Kolkata timezone

// Calculate remaining time
$currentTimestamp = time();
$remainingTime = $expiresInSeconds - $currentTimestamp;

// Get user details properly
$account = array(
    'expiresAt' => $expiresAt,
    'remainingTime' => $remainingTime,
    'sid' => $json['data']['userDetails']['sid'],
    'sName' => $json['data']['userDetails']['sName'],
    'acStatus' => $json['data']['userDetails']['acStatus'] ?? "INACTIVE" // Default to INACTIVE if not set
);

// Determine default toggle state
$defaultM3UType = ($account['acStatus'] === "ACTIVE") ? "Widevine" : "ClearKey";

// Get the current URL for playlists
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$playlistUrl = $protocol . $host . dirname($_SERVER['PHP_SELF']) . '/Playlist.m3u';
$playlistUrl2 = $protocol . $host . dirname($_SERVER['PHP_SELF']) . '/Playlist2.m3u';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TP Script</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link href="style.css" rel="stylesheet">
    <script>
        function startCountdown(expirationTime) {
            function updateCountdown() {
                let now = Math.floor(Date.now() / 1000);
                let remaining = expirationTime - now;

                if (remaining < 0) {
                    document.getElementById('countdown').innerHTML = "Expired";
                    clearInterval(countdownInterval);
                    return;
                }

                let days = Math.floor(remaining / 86400);
                let hours = Math.floor((remaining % 86400) / 3600);
                let minutes = Math.floor((remaining % 3600) / 60);
                let seconds = remaining % 60;

                let countdownText = `${days}d ${hours}h ${minutes}m ${seconds}s`;
                document.getElementById('countdown').innerHTML = countdownText;
            }

            updateCountdown();
            let countdownInterval = setInterval(updateCountdown, 1000);
        }

        function copyPlaylistUrl() {
            const urlInput = document.createElement("input");
            const playlistType = document.getElementById("toggleM3U").checked ? "<?= htmlspecialchars($playlistUrl) ?>" : "<?= htmlspecialchars($playlistUrl2) ?>";
            urlInput.value = playlistType;
            document.body.appendChild(urlInput);
            urlInput.select();
            document.execCommand('copy');
            document.body.removeChild(urlInput);

            const btn = document.querySelector('button');
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = 'Copy Link'; }, 1000);
        }

        function togglePlaylist() {
            const isChecked = document.getElementById("toggleM3U").checked;
            document.getElementById("m3uLabel").innerText = isChecked ? "Widevine" : "ClearKey";
            document.getElementById("m3uLabel").className = "m3u-type";
            document.getElementById("playlistUrlDisplay").innerText = isChecked ? "<?= htmlspecialchars($playlistUrl) ?>" : "<?= htmlspecialchars($playlistUrl2) ?>";
        }

        window.onload = function() {
            startCountdown(<?= $account['remainingTime'] + time() ?>);
        };
    </script>
</head>
<body>
    <div class="top-right"></div>
    <h1><span>TP</span> User Profile</h1>
    <form method="POST">
        <div id="loginForm">
            <label><strong><i class="material-icons">person</i> Sub-Name :-</strong> <?= $account['sName'] ?></label>
            <label><strong><i class="material-icons">key</i> Password :-</strong> <?= $account['sid'] ?></label>
            <label><strong><i class="material-icons">toggle_on</i> Status:</strong> <?= $account['acStatus'] ?></label>
            <label><strong><i class="material-icons">timer</i> Expires At :-</strong> <?= $account['expiresAt'] ?></label>
            <label><strong><i class="material-icons">hourglass_empty</i> Countdown:</strong> <span id="countdown"></span></label>

            <!-- Toggle Switch -->
            <label>
                <strong><i class="material-icons" id="m3uIcon">toggle_on</i> M3U Type ( Switch button ) :- </strong> 
                <span id="m3uLabel"><?= $defaultM3UType ?></span>
            </label>
            <input type="checkbox" id="toggleM3U" onchange="togglePlaylist()" <?= ($defaultM3UType === "Widevine") ? "checked" : "" ?>>

            <!-- Playlist Display -->
            <label>
                <strong><i class="material-icons">attach_file</i> M3U URL: </strong>
                <p id="playlistUrlDisplay"><?= ($defaultM3UType === "Widevine") ? htmlspecialchars($playlistUrl) : htmlspecialchars($playlistUrl2) ?></p>
            </label>

            <button type="button" onclick="copyPlaylistUrl()">Copy Link</button>
            <button type="submit" formaction="login.php">Logout</button>
        </div>
    </form>

    <h2>Coded with ❤️ by <a href="https://t.me/DenverIsAlivee" target="_blank">Denver1769</a></h2>
</body>
</html>