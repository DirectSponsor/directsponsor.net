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

echo json_encode([
    'success' => true,
    'post_id' => $post['post_id'],
    'filename' => basename($postFile),
]);
