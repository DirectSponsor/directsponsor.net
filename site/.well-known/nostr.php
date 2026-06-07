<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');

$requestedName = isset($_GET['name']) ? trim($_GET['name']) : null;

$names = [];
$profileFiles = glob(USERDATA_DIR . '/profiles/*.txt');
foreach ($profileFiles as $file) {
    $profile = json_decode(file_get_contents($file), true);
    if (!$profile || empty($profile['nostr_pubkey'])) continue;
    $username = $profile['username'] ?? null;
    if (!$username) continue;
    if ($requestedName && $username !== $requestedName) continue;
    $names[$username] = $profile['nostr_pubkey'];
}

echo json_encode(['names' => $names], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
