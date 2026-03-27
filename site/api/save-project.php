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
define('USERDATA_DIR', DS_DATA_DIR);
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
$callerId = null;

if ($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload) {
            $callerUsername = $payload['username'] ?? $payload['sub'] ?? null;
            $callerId       = $payload['user_id'] ?? $payload['sub'] ?? null;
        }
    }
}

if (!$callerUsername && !empty($input['username'])) {
    $callerUsername = $input['username'];
}

if (!$callerUsername) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Load roles from profile file (JWT won't contain them)
$callerRoles = ['member'];
if ($callerId) {
    $glob = glob(USERDATA_DIR . '/profiles/' . $callerId . '-*.txt');
    $pfile = $glob ? $glob[0] : USERDATA_DIR . '/profiles/' . $callerId . '.txt';
    if (file_exists($pfile)) {
        $pd = json_decode(file_get_contents($pfile), true);
        $callerRoles = $pd['roles'] ?? ['member'];
    }
}

// Only recipients may create/edit projects
if (!in_array('recipient', $callerRoles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Recipient role required to manage projects']);
    exit;
}

$isAdmin = in_array('admin', $callerRoles);
$target_username = $input['username'] ?? $callerUsername;

// Permission check: must be own project
if ($target_username !== $callerUsername) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only edit your own projects']);
    exit;
}

// Resolve project_id: if provided and file exists, edit it; otherwise auto-assign next free number
$raw_project_id = preg_replace('/[^a-z0-9-]/', '', strtolower($input['project_id'] ?? ''));
$userProjectDir  = PROJECTS_DIR . '/' . $target_username;

if ($raw_project_id) {
    // Explicit ID requested — use it (editing existing or creating at specific slot)
    $project_id = $raw_project_id;
} else {
    // Auto-assign: find lowest number not used in active/ or completed/
    $used = [];
    foreach (glob($userProjectDir . '/active/*.html') ?: [] as $f) {
        if (preg_match('/\/(\d+)\.html$/', $f, $m)) $used[] = intval($m[1]);
    }
    foreach (glob($userProjectDir . '/completed/*.html') ?: [] as $f) {
        if (preg_match('/\/(\d+)\.html$/', $f, $m)) $used[] = intval($m[1]);
    }
    $next = 1;
    while (in_array($next, $used)) $next++;
    $project_id = str_pad($next, 3, '0', STR_PAD_LEFT);
}

// Find or create the project HTML file
$htmlFile = PROJECTS_DIR . '/' . $target_username . '/active/' . $project_id . '.html';
if (!file_exists($htmlFile)) {
    // New project — create directory and stub file from template
    $dir = dirname($htmlFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Minimal HTML stub with all required comment tags
    $stub = '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title><!-- title -->Untitled Project<!-- end title --></title></head>
<body>
<!-- OWNER: ' . htmlspecialchars($target_username, ENT_QUOTES) . ' -->
<!-- title -->Untitled Project<!-- end title -->
<!-- description --><!-- end description -->
<!-- full-description --><!-- end full-description -->
<!-- target-amount -->0<!-- end target-amount -->
<!-- current-amount -->0<!-- end current-amount -->
<!-- recipient-name -->' . htmlspecialchars($target_username, ENT_QUOTES) . '<!-- end recipient-name -->
<!-- category -->General<!-- end category -->
<!-- status -->active<!-- end status -->
<!-- location --><!-- end location -->
<!-- website-url --><!-- end website-url -->
<!-- lightning-address --><!-- end lightning-address -->
</body></html>';
    file_put_contents($htmlFile, $stub);
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
    'coinos-api-key'   => $input['coinos_api_key']    ?? null,
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

// Write config.json if a Coinos API key was provided (fall back to profile if empty)
$coinosApiKey = trim($input['coinos_api_key'] ?? '');
if (!$coinosApiKey) {
    // Try profile file
    $profileGlob = glob(USERDATA_DIR . '/profiles/' . $callerId . '-*.txt');
    if ($profileGlob) {
        $profileData = json_decode(file_get_contents($profileGlob[0]), true);
        $coinosApiKey = trim($profileData['coinos_api_key'] ?? '');
    }
}
if ($coinosApiKey) {
    $configFile = PROJECTS_DIR . '/' . $target_username . '/' . $project_id . '-config.json';
    $existingConfig = file_exists($configFile)
        ? (json_decode(file_get_contents($configFile), true) ?? [])
        : [];
    $existingConfig['project_id'] = $project_id;
    $existingConfig['recipient_wallet'] = [
        'type'            => 'coinos',
        'api_key'         => $coinosApiKey,
        'coinos_username' => $target_username,
    ];
    file_put_contents($configFile, json_encode($existingConfig, JSON_PRETTY_PRINT));
}

echo json_encode([
    'success'    => true,
    'message'    => 'Project saved',
    'project_id' => $project_id,
    'username'   => $target_username,
]);
