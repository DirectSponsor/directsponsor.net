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

function nostr_build_kind0_content($profile, $username) {
    $meta = ['name' => $username, 'nip05' => $username . '@directsponsor.net'];
    if (!empty($profile['display_name']))     $meta['display_name'] = $profile['display_name'];
    if (!empty($profile['bio']))              $meta['about']        = trim(strip_tags($profile['bio']));
    if (!empty($profile['website']))          $meta['website']      = $profile['website'];
    if (!empty($profile['lightning_address'])) $meta['lud16']       = $profile['lightning_address'];
    if (!empty($profile['picture'])) {
        $pic = $profile['picture'];
        if (strpos($pic, 'http') !== 0) $pic = 'https://directsponsor.net' . $pic;
        $meta['picture'] = $pic;
    }
    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function nostr_publish_ws($event_json, $host, $port, $ssl = false) {
    $scheme = $ssl ? 'ssl' : 'tcp';
    $ctx = $ssl ? stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'SNI_enabled'      => true,
        'peer_name'        => $host,
    ]]) : null;
    $sock = $ctx
        ? @stream_socket_client("$scheme://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx)
        : @stream_socket_client("$scheme://$host:$port", $errno, $errstr, 5);
    if (!$sock) return false;
    $wsHost = $ssl ? $host : "$host:$port";
    $key = base64_encode(random_bytes(16));
    $handshake = "GET / HTTP/1.1\r\nHost: $wsHost\r\nUpgrade: websocket\r\n"
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

function extract_first_url($text) {
    $plain = strip_tags($text);
    if (preg_match('#https?://[^\s<>"\']+#i', $plain, $m)) {
        return rtrim($m[0], '.,;:!?)]}');
    }
    return null;
}

function fetch_link_preview($url) {
    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'])) return null;
    $host = $parts['host'] ?? '';
    if (!$host) return null;
    $ip = gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 5,
        'header'        => "User-Agent: Mozilla/5.0 (compatible; DS-LinkPreview/1.0)\r\n",
        'max_redirects' => 3,
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx, 0, 60000);
    if (!$html) return null;

    $get_og = function($html, $prop) {
        if (preg_match('/<meta[^>]+property=["\']' . preg_quote($prop, '/') . '["\'][^>]+content=["\']([^"\']*)/i', $html, $m)) return $m[1];
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']' . preg_quote($prop, '/') . '["\'][^>]*/i', $html, $m)) return $m[1];
        return '';
    };

    $title       = html_entity_decode($get_og($html, 'og:title'), ENT_QUOTES, 'UTF-8');
    $description = html_entity_decode($get_og($html, 'og:description'), ENT_QUOTES, 'UTF-8');
    $image       = $get_og($html, 'og:image');

    if (!$title && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m))
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    if (!$description && preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/i', $html, $m))
        $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

    if (!$title && !$image) return null;

    return [
        'url'         => $url,
        'title'       => substr($title, 0, 200),
        'description' => substr($description, 0, 300),
        'image'       => $image,
        'domain'      => $host,
    ];
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

require_once __DIR__ . '/jwt-verify.php';

// Auth — verified JWT required
$caller         = getCallerFromJwt($input);
$callerUsername = $caller ? $caller['username'] : null;
$callerId       = $caller ? $caller['user_id']  : null;

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

$previewUrl = extract_first_url($intro . ' ' . $body);
$post['link_preview'] = $previewUrl ? fetch_link_preview($previewUrl) : null;

file_put_contents($postFile, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Nostr: get or create keypair, sign event, import to relay
$nostrPubkey = null;
$external_relays = [
    ['host' => 'relay.damus.io',   'port' => 443, 'ssl' => true],
    ['host' => 'relay.primal.net', 'port' => 443, 'ssl' => true],
    ['host' => 'nos.lol',          'port' => 443, 'ssl' => true],
];
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
        $kind0Content = nostr_build_kind0_content($profile, $callerUsername);
        $kind0Hash = md5($kind0Content);
        if (($profile['nostr_metadata_hash'] ?? '') !== $kind0Hash) {
            $kind0Event = json_encode([
                'kind'       => 0,
                'created_at' => time(),
                'tags'       => [],
                'content'    => $kind0Content,
            ], JSON_UNESCAPED_UNICODE);
            $kind0Signed = shell_exec('/usr/bin/python3 /opt/strfry/nostr-sign.py sign '
                . escapeshellarg($profile['nostr_privkey']) . ' '
                . escapeshellarg($kind0Event) . ' 2>/dev/null');
            if ($kind0Signed) {
                nostr_publish_ws(trim($kind0Signed), '127.0.0.1', 7777);
                foreach ($external_relays as $r) {
                    nostr_publish_ws(trim($kind0Signed), $r['host'], $r['port'], $r['ssl']);
                }
                $profile['nostr_metadata_hash'] = $kind0Hash;
                file_put_contents($profileFile, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
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
            foreach ($external_relays as $r) {
                nostr_publish_ws(trim($signedJson), $r['host'], $r['port'], $r['ssl']);
            }
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
