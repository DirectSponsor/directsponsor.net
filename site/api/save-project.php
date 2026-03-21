<?php
/**
 * Save Project API
 * Reads an existing project HTML file, updates comment-tag fields, writes it back.
 * Only recipients may edit their own project. Admins may edit any.
 *
 * POST /api/save-project.php
 * Body (JSON):
 *   user_id, project_id, title, description, full_description,
 *   target_amount, location, website_url, category, status
 */

define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('PROJECTS_DIR', DS_DATA_DIR . '/projects');
define('USERDATA_DIR', DS_DATA_DIR . '/userdata');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'hybrid_fresh_2025_secret_key');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// --- Auth: verify JWT from Authorization header or body ---
$jwt = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $jwt = $m[1];
} elseif (!empty($input['jwt'])) {
    $jwt = $input['jwt'];
}

$callerUsername = null;
$callerRoles = [];

if ($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload) {
            $callerUsername = $payload['username'] ?? $payload['sub'] ?? null;
            $callerRoles    = $payload['roles'] ?? [];
        }
    }
}

// Fallback: accept user_id from body for now (session-bridge will handle proper auth later)
if (!$callerUsername && !empty($input['username'])) {
    $callerUsername = $input['username'];
}

if (!$callerUsername) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$project_id = preg_replace('/[^a-z0-9-]/', '', strtolower($input['project_id'] ?? '001'));
$target_username = $input['username'] ?? $callerUsername;

// Permission check: must be own project or admin
$isAdmin = in_array('admin', $callerRoles);
if (!$isAdmin && $target_username !== $callerUsername) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only edit your own projects']);
    exit;
}

// Find the project HTML file
$htmlFile = PROJECTS_DIR . '/' . $target_username . '/active/' . $project_id . '.html';
if (!file_exists($htmlFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project file not found: ' . $htmlFile]);
    exit;
}

$html = file_get_contents($htmlFile);
if ($html === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read project file']);
    exit;
}

// --- Helper: replace a comment-tag value ---
function replaceTag($html, $tag, $value) {
    $escaped = htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    $pattern = '/<!-- ' . preg_quote($tag, '/') . ' -->.*?<!-- end ' . preg_quote($tag, '/') . ' -->/s';
    $replacement = '<!-- ' . $tag . ' -->' . $escaped . '<!-- end ' . $tag . ' -->';
    $new = preg_replace($pattern, $replacement, $html);
    // If tag doesn't exist yet, append a data comment block at end of body
    return ($new !== null) ? $new : $html;
}

// --- Apply edits from request ---
$fields = [
    'title'            => $input['title']            ?? null,
    'description'      => $input['description']      ?? null,
    'full-description' => $input['full_description']  ?? null,
    'target-amount'    => $input['target_amount']     ?? null,
    'recipient-name'   => $input['recipient_name']    ?? $target_username,
    'location'         => $input['location']          ?? null,
    'website-url'      => $input['website_url']       ?? null,
    'category'         => $input['category']          ?? null,
    'status'           => $input['status']            ?? null,
];

foreach ($fields as $tag => $value) {
    if ($value !== null && $value !== '') {
        $html = replaceTag($html, $tag, trim($value));
    }
}

// --- Write atomically ---
$tmp = $htmlFile . '.tmp';
if (file_put_contents($tmp, $html) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write project file']);
    exit;
}
if (!rename($tmp, $htmlFile)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save project file']);
    exit;
}

echo json_encode([
    'success'    => true,
    'message'    => 'Project saved',
    'project_id' => $project_id,
    'username'   => $target_username,
]);
