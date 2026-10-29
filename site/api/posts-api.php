<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('USERDATA_DIR', getenv('DS_USERDATA') ?: '/var/www/directsponsor.net/userdata');

function commentCount($username, $postId) {
    $file = USERDATA_DIR . '/comments/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($username)) . '-' . preg_replace('/[^0-9]/', '', $postId) . '.json';
    if (!file_exists($file)) return 0;
    $data = json_decode(file_get_contents($file), true);
    return count($data['comments'] ?? []);
}

function ds_profile($username) {
    static $cache = [];
    $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($username));
    if ($username === '') return null;
    if (array_key_exists($username, $cache)) return $cache[$username];
    $cache[$username] = null;
    foreach (glob(USERDATA_DIR . '/profiles/*-' . $username . '.txt') ?: [] as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && strtolower($data['username'] ?? '') === $username) {
            $cache[$username] = $data;
            break;
        }
    }
    return $cache[$username];
}

function ds_plain($html, $max) {
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $n = preg_match_all('/./u', $text, $m);
    if ($n === false || $n <= $max) return $text;
    preg_match('/^.{0,' . ($max - 1) . '}/u', $text, $cut);
    return rtrim($cut[0]) . '…';
}

function ds_abs_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if ($url[0] === '/') return 'https://directsponsor.net' . $url;
    return '';
}

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
                $pid = $data['post_id'] ?? '';
                $uname = $data['username'] ?? '';
                $posts[] = [
                    'post_id'       => $pid,
                    'username'      => $uname,
                    'title'         => $data['title'] ?? '',
                    'intro'         => $data['intro'] ?? '',
                    'has_body'      => !empty($data['body']),
                    'image_url'     => $data['image_url'] ?? '',
                    'link_preview'  => $data['link_preview'] ?? null,
                    'link'          => $data['link'] ?? '',
                    'kind'          => (($data['kind'] ?? '') === 'news') ? 'news' : 'post',
                    'created'       => $data['created'] ?? 0,
                    'updated'       => $data['updated'] ?? 0,
                    'comment_count' => commentCount($uname, $pid),
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
            $pid = $data['post_id'] ?? '';
            $uname = $data['username'] ?? '';
            $posts[] = [
                'post_id'       => $pid,
                'username'      => $uname,
                'title'         => $data['title'] ?? '',
                'intro'         => $data['intro'] ?? '',
                'has_body'      => !empty($data['body']),
                'image_url'     => $data['image_url'] ?? '',
                'link_preview'  => $data['link_preview'] ?? null,
                'link'          => $data['link'] ?? '',
                'kind'          => (($data['kind'] ?? '') === 'news') ? 'news' : 'post',
                'created'       => $data['created'] ?? 0,
                'comment_count' => commentCount($uname, $pid),
            ];
        }
    }

    usort($posts, fn($a, $b) => $b['created'] - $a['created']);
    echo json_encode(['success' => true, 'posts' => $posts]);
    exit;
}

// GET news posts from recipients — one call for the social-draft job
if ($action === 'news_feed') {
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $since = (int)($_GET['since'] ?? 0);

    $posts = [];
    $postsBase = USERDATA_DIR . '/posts';
    if (is_dir($postsBase)) {
        foreach (glob($postsBase . '/*/[0-9]*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data || ($data['kind'] ?? '') !== 'news') continue;
            $created = (int)($data['created'] ?? 0);
            if ($since && $created <= $since) continue;
            $uname = preg_replace('/[^a-z0-9_-]/', '', strtolower($data['username'] ?? ''));
            if (!$uname) continue;
            $profile = ds_profile($uname);
            if (!$profile || !in_array('recipient', $profile['roles'] ?? [], true)) continue;

            $preview = is_array($data['link_preview'] ?? null) ? $data['link_preview'] : [];
            $image = ds_abs_url($data['image_url'] ?? '');
            if ($image === '' && empty($preview['blocked'])) $image = ds_abs_url($preview['image'] ?? '');
            $pid = (string)($data['post_id'] ?? '');

            $posts[] = [
                'post_id'      => $pid,
                'username'     => $uname,
                'display_name' => ds_plain($profile['display_name'] ?? '', 80) ?: $uname,
                'bio'          => ds_plain($profile['bio'] ?? '', 400),
                'website'      => preg_match('#^https?://#i', trim($profile['website'] ?? '')) ? trim($profile['website'] ?? '') : '',
                'title'        => ds_plain($data['title'] ?? '', 100),
                'description'  => ds_plain($data['intro'] ?? '', 200),
                'body'         => ds_plain($data['body'] ?? '', 2000),
                'link'         => $data['link'] ?? '',
                'link_title'   => ds_plain($preview['title'] ?? '', 200),
                'image'        => $image,
                'share_url'    => 'https://directsponsor.net/posts.html?user=' . rawurlencode($uname) . '&post_id=' . rawurlencode($pid),
                'created'      => $created,
            ];
        }
    }

    usort($posts, fn($a, $b) => $b['created'] - $a['created']);
    $total = count($posts);
    $posts = array_slice($posts, 0, $limit);
    echo json_encode(['success' => true, 'posts' => $posts, 'total' => $total]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
