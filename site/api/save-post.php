<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');

function nostr_publish_ws($event_json, $host, $port) {
    $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
    if (!$sock) return false;
    $key = base64_encode(random_bytes(16));
    $handshake = "GET / HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\n"
        . "Connection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
    fwrite($sock, $handshake);
    $response = '';
    while (!feof($sock)) {
        $response .= fread($sock, 1024);
        if (strpos($response, "\r\n\r\n") !== false) break;
    }
    if (strpos($response, '101') === false) { fclose($sock); return false; }
    $msg = '["EVENT",' . $event_json . ']';
    $len = strlen($msg);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) $masked .= chr(ord($msg[$i]) ^ ord($mask[$i % 4]));
    $frame = ($len <= 125)
        ? chr(0x81) . chr(0x80 | $len) . $mask . $masked
        : chr(0x81) . chr(0xFE) . pack('n', $len) . $mask . $masked;
    fwrite($sock, $frame);
    stream_set_timeout($sock, 2);
    fread($sock, 256);
    fclose($sock);
    return true;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Auth
$jwt = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $jwt = $m[1];

$callerUsername = null;
$callerId = null;

if (!$jwt && !empty($input['jwt'])) {
    $jwt = $input['jwt'];
}

if ($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload) {
            $callerUsername = $payload['username'] ?? null;
            $callerId       = $payload['user_id'] ?? $payload['sub'] ?? null;
        }
    }
}

if (!$callerUsername && !empty($input['username'])) {
    $callerUsername = $input['username'];
}

if (!$callerUsername || !$callerId) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Parse fields
$intro = trim($input['intro'] ?? '');
$title = trim($input['title'] ?? '');
$body  = trim($input['body'] ?? '');
$image_url = trim($input['image_url'] ?? '');
$post_id = trim($input['post_id'] ?? '');

if (!$intro) {
    http_response_code(400);
    echo json_encode(['error' => 'Post text is required']);
    exit;
}

// Editing existing post vs creating new
$postsDir = USERDATA_DIR . '/posts/' . $callerUsername;
if (!is_dir($postsDir)) {
    mkdir($postsDir, 0755, true);
}

if ($post_id) {
    // Edit: find the file
    $existing = glob($postsDir . '/' . $post_id . '-*.json');
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Post not found']);
        exit;
    }
    $postFile = $existing[0];
    $post = json_decode(file_get_contents($postFile), true) ?: [];
    $post['title']     = $title;
    $post['intro']     = $intro;
    $post['body']      = $body;
    $post['image_url'] = $image_url;
    $post['updated']   = time();
} else {
    // New post
    $ts = time();
    $slug = $title
        ? preg_replace('/[^a-z0-9]+/', '-', strtolower($title))
        : 'post';
    $slug = trim($slug, '-');
    $post_id = $ts;
    $postFile = $postsDir . '/' . $ts . '-' . $slug . '.json';
    $post = [
        'post_id'   => (string)$ts,
        'username'  => $callerUsername,
        'user_id'   => $callerId,
        'title'     => $title,
        'intro'     => $intro,
        'body'      => $body,
        'image_url' => $image_url,
        'created'   => $ts,
        'updated'   => $ts,
    ];
}

file_put_contents($postFile, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Nostr: get or create keypair, sign event, import to relay
$nostrPubkey = null;
$profileGlob = glob(USERDATA_DIR . '/profiles/*-' . $callerUsername . '.txt');
if ($profileGlob) {
    $profileFile = $profileGlob[0];
    $profile = json_decode(file_get_contents($profileFile), true) ?: [];
    if (empty($profile['nostr_privkey'])) {
        $keyJson = shell_exec('/usr/bin/python3 /opt/strfry/nostr-sign.py genkey 2>/dev/null');
        $keys = $keyJson ? json_decode($keyJson, true) : null;
        if ($keys) {
            $profile['nostr_privkey'] = $keys['privkey'];
            $profile['nostr_pubkey']  = $keys['pubkey'];
            file_put_contents($profileFile, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    if (!empty($profile['nostr_privkey'])) {
        $nostrPubkey = $profile['nostr_pubkey'];
        $content = $title ? $title . "\n\n" . $intro : $intro;
        if ($body) $content .= "\n\n" . strip_tags($body);
        if ($image_url) {
            $absImage = (strpos($image_url, 'http') === 0)
                ? $image_url
                : 'https://directsponsor.net' . $image_url;
            $content .= "\n\n" . $absImage;
        }
        $nostrEvent = json_encode([
            'kind'       => 1,
            'created_at' => $post['created'] ?? time(),
            'tags'       => [['r', 'https://directsponsor.net/posts.html?user=' . $callerUsername . '&post_id=' . $post['post_id']]],
            'content'    => $content,
        ], JSON_UNESCAPED_UNICODE);
        $signedJson = shell_exec('/usr/bin/python3 /opt/strfry/nostr-sign.py sign '
            . escapeshellarg($profile['nostr_privkey']) . ' '
            . escapeshellarg($nostrEvent) . ' 2>/dev/null');
        if ($signedJson) {
            $signed = json_decode(trim($signedJson), true);
            if ($signed && !empty($signed['id'])) {
                $post['nostr_event_id'] = $signed['id'];
                $post['nostr_pubkey']   = $signed['pubkey'];
                file_put_contents($postFile, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            nostr_publish_ws(trim($signedJson), '127.0.0.1', 7777);
        }
    }
}

echo json_encode([
    'success'        => true,
    'post_id'        => $post['post_id'],
    'filename'       => basename($postFile),
    'nostr_pubkey'   => $nostrPubkey,
    'nostr_event_id' => $post['nostr_event_id'] ?? null,
]);
