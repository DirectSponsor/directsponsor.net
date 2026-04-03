<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');

$action = $_GET['action'] ?? 'feed';

// GET feed - all posts from all users, reverse chronological
if ($action === 'feed') {
    $limit  = min((int)($_GET['limit'] ?? 20), 50);
    $offset = (int)($_GET['offset'] ?? 0);

    $posts = [];
    $postsBase = USERDATA_DIR . '/posts';

    if (is_dir($postsBase)) {
        foreach (glob($postsBase . '/*/[0-9]*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && !empty($data['intro'])) {
                $posts[] = [
                    'post_id'   => $data['post_id'] ?? '',
                    'username'  => $data['username'] ?? '',
                    'title'     => $data['title'] ?? '',
                    'intro'     => $data['intro'] ?? '',
                    'has_body'  => !empty($data['body']),
                    'image_url' => $data['image_url'] ?? '',
                    'created'   => $data['created'] ?? 0,
                    'updated'   => $data['updated'] ?? 0,
                ];
            }
        }
    }

    usort($posts, fn($a, $b) => $b['created'] - $a['created']);
    $total = count($posts);
    $posts = array_slice($posts, $offset, $limit);

    echo json_encode(['success' => true, 'posts' => $posts, 'total' => $total]);
    exit;
}

// GET single post (full content including body)
if ($action === 'post') {
    $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['username'] ?? ''));
    $post_id  = preg_replace('/[^0-9]/', '', $_GET['post_id'] ?? '');

    if (!$username || !$post_id) {
        http_response_code(400);
        echo json_encode(['error' => 'username and post_id required']);
        exit;
    }

    $files = glob(USERDATA_DIR . '/posts/' . $username . '/' . $post_id . '-*.json');
    if (!$files) {
        http_response_code(404);
        echo json_encode(['error' => 'Post not found']);
        exit;
    }

    $data = json_decode(file_get_contents($files[0]), true);
    echo json_encode(['success' => true, 'post' => $data]);
    exit;
}

// GET posts by a specific user
if ($action === 'user_posts') {
    $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['username'] ?? ''));
    if (!$username) {
        http_response_code(400);
        echo json_encode(['error' => 'username required']);
        exit;
    }

    $posts = [];
    foreach (glob(USERDATA_DIR . '/posts/' . $username . '/[0-9]*.json') ?: [] as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && !empty($data['intro'])) {
            $posts[] = [
                'post_id'   => $data['post_id'] ?? '',
                'username'  => $data['username'] ?? '',
                'title'     => $data['title'] ?? '',
                'intro'     => $data['intro'] ?? '',
                'has_body'  => !empty($data['body']),
                'image_url' => $data['image_url'] ?? '',
                'created'   => $data['created'] ?? 0,
            ];
        }
    }

    usort($posts, fn($a, $b) => $b['created'] - $a['created']);
    echo json_encode(['success' => true, 'posts' => $posts]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
