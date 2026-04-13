<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');
define('COMMENTS_DIR', USERDATA_DIR . '/comments');

// --- Helpers ---

function jwtUsername($jwt) {
    if (!$jwt) return null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return $payload['username'] ?? null;
}

function commentsFile($postAuthor, $postId) {
    $a = preg_replace('/[^a-z0-9_-]/', '', strtolower($postAuthor));
    $b = preg_replace('/[^0-9]/', '', $postId);
    return COMMENTS_DIR . '/' . $a . '-' . $b . '.json';
}

function loadComments($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return $data['comments'] ?? [];
}

function saveComments($file, $comments) {
    if (!is_dir(COMMENTS_DIR)) mkdir(COMMENTS_DIR, 0755, true);
    $tmp = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode(['comments' => array_values($comments)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

// --- GET ---

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $postAuthor = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['username'] ?? ''));
    $postId     = preg_replace('/[^0-9]/', '', $_GET['post_id'] ?? '');

    if (!$postAuthor || !$postId) {
        http_response_code(400);
        echo json_encode(['error' => 'username and post_id required']);
        exit;
    }

    $comments = loadComments(commentsFile($postAuthor, $postId));
    echo json_encode(['success' => true, 'comments' => $comments]);
    exit;
}

// --- POST ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $jwt = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $jwt = $m[1];
    if (!$jwt && !empty($input['jwt'])) $jwt = $input['jwt'];

    $author = jwtUsername($jwt);
    if (!$author) {
        http_response_code(401);
        echo json_encode(['error' => 'Login required to comment']);
        exit;
    }

    $action     = $input['action'] ?? 'post';
    $postAuthor = preg_replace('/[^a-z0-9_-]/', '', strtolower($input['username'] ?? ''));
    $postId     = preg_replace('/[^0-9]/', '', $input['post_id'] ?? '');

    if (!$postAuthor || !$postId) {
        http_response_code(400);
        echo json_encode(['error' => 'username and post_id required']);
        exit;
    }

    $file     = commentsFile($postAuthor, $postId);
    $comments = loadComments($file);

    // Add a comment or reply
    if ($action === 'post') {
        $body     = trim($input['body'] ?? '');
        $parentId = $input['parent_id'] ?? null;

        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Comment body required']);
            exit;
        }
        if (mb_strlen($body) > 1000) {
            http_response_code(400);
            echo json_encode(['error' => 'Comment too long (max 1000 chars)']);
            exit;
        }

        // Validate parent: must be a top-level comment on this post
        if ($parentId !== null) {
            $validParent = false;
            foreach ($comments as $c) {
                if ($c['id'] === $parentId && $c['parent_id'] === null) {
                    $validParent = true;
                    break;
                }
            }
            if (!$validParent) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid parent comment']);
                exit;
            }
        }

        $comment = [
            'id'        => time() . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'author'    => $author,
            'body'      => htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
            'timestamp' => time(),
            'parent_id' => $parentId,
        ];

        $comments[] = $comment;
        saveComments($file, $comments);
        echo json_encode(['success' => true, 'comment' => $comment]);
        exit;
    }

    // Delete own comment (also removes its replies)
    if ($action === 'delete') {
        $commentId  = $input['comment_id'] ?? '';
        $found      = false;
        $newComments = [];

        foreach ($comments as $c) {
            if ($c['id'] === $commentId) {
                if ($c['author'] !== $author) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not your comment']);
                    exit;
                }
                $found = true;
                continue;
            }
            if ($c['parent_id'] === $commentId) continue; // prune replies too
            $newComments[] = $c;
        }

        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Comment not found']);
            exit;
        }

        saveComments($file, $newComments);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
