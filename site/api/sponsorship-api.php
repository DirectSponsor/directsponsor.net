<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');
define('GROUPS_DIR',   USERDATA_DIR . '/sponsorship-groups');

// ---------------------------------------------------------------------------
// Auth helpers
// ---------------------------------------------------------------------------

function getCallerFromJwt($input) {
    $jwt = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) $jwt = $m[1];
    if (!$jwt && !empty($input['jwt'])) $jwt = $input['jwt'];

    if (!$jwt) return null;

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$payload) return null;

    $username = $payload['username'] ?? null;
    $userId   = $payload['user_id'] ?? $payload['sub'] ?? null;
    if (!$username || !$userId) return null;

    return ['username' => $username, 'user_id' => $userId];
}

function getProfileRoles($username) {
    $glob = glob(USERDATA_DIR . '/profiles/*-' . preg_quote($username, '/') . '.txt');
    if (!$glob) return ['member'];
    $data = json_decode(file_get_contents($glob[0]), true);
    return $data['roles'] ?? ['member'];
}

function hasRole($username, $role) {
    return in_array($role, getProfileRoles($username));
}

// ---------------------------------------------------------------------------
// Group file helpers
// ---------------------------------------------------------------------------

function groupPath($recipient) {
    return GROUPS_DIR . '/' . preg_replace('/[^a-z0-9_\-]/', '', strtolower($recipient)) . '.json';
}

function readGroup($recipient) {
    $path = groupPath($recipient);
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function writeGroup($recipient, $data) {
    if (!is_dir(GROUPS_DIR)) mkdir(GROUPS_DIR, 0755, true);
    file_put_contents(groupPath($recipient), json_encode($data, JSON_PRETTY_PRINT));
}

function memberIndex($members, $username) {
    foreach ($members as $i => $m) {
        if (($m['username'] ?? '') === $username) return $i;
    }
    return -1;
}

function tierCounts($members) {
    $counts = ['active' => 0, 'standby' => 0, 'queued' => 0];
    foreach ($members as $m) {
        $tier = $m['tier'] ?? 'queued';
        if (isset($counts[$tier])) $counts[$tier]++;
    }
    return $counts;
}

function publicGroup($group) {
    $out = [
        'recipient_username'    => $group['recipient_username'],
        'description'           => $group['description'] ?? '',
        'suggested_monthly_sats'=> $group['suggested_monthly_sats'] ?? 0,
        'created_date'          => $group['created_date'] ?? '',
        'counts'                => tierCounts($group['members'] ?? []),
        'members'               => [],
    ];
    foreach ($group['members'] ?? [] as $m) {
        $out['members'][] = [
            'username'     => $m['username'],
            'display_name' => $m['display_name'] ?? $m['username'],
            'tier'         => $m['tier'],
            'joined_date'  => $m['joined_date'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $input = json_decode($raw, true) ?? [];
    foreach ($_POST as $k => $v) { if (!isset($input[$k])) $input[$k] = $v; }
}

// GET: list all groups
if ($method === 'GET' && $action === 'list') {
    if (!is_dir(GROUPS_DIR)) { echo json_encode(['groups' => []]); exit; }
    $groups = [];
    foreach (glob(GROUPS_DIR . '/*.json') as $file) {
        $g = json_decode(file_get_contents($file), true);
        if ($g) $groups[] = publicGroup($g);
    }
    echo json_encode(['groups' => $groups]);
    exit;
}

// GET: get a single group
if ($method === 'GET' && $action === 'get') {
    $recipient = trim($_GET['username'] ?? '');
    if (!$recipient) { http_response_code(400); echo json_encode(['error' => 'username required']); exit; }
    $group = readGroup($recipient);
    if (!$group) { echo json_encode(['success' => false, 'group' => null]); exit; }
    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

// POST: setup — recipient creates or updates their group settings
if ($method === 'POST' && $action === 'setup') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient = $caller['username'];
    if (!hasRole($recipient, 'recipient') && !hasRole($recipient, 'admin')) {
        http_response_code(403); echo json_encode(['error' => 'Recipient role required']); exit;
    }

    $group = readGroup($recipient) ?? [
        'recipient_username' => $recipient,
        'members'            => [],
        'created_date'       => date('Y-m-d'),
    ];

    if (isset($input['description']))            $group['description']            = substr(trim($input['description']), 0, 500);
    if (isset($input['suggested_monthly_sats'])) $group['suggested_monthly_sats'] = max(0, (int)$input['suggested_monthly_sats']);

    writeGroup($recipient, $group);
    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

// POST: join — logged-in user joins a recipient's queue
if ($method === 'POST' && $action === 'join') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient = trim($input['recipient'] ?? '');
    if (!$recipient) { http_response_code(400); echo json_encode(['error' => 'recipient required']); exit; }
    if ($caller['username'] === $recipient) {
        http_response_code(400); echo json_encode(['error' => 'You cannot join your own group']); exit;
    }

    $group = readGroup($recipient);
    if (!$group) { http_response_code(404); echo json_encode(['error' => 'No sponsorship group found for this recipient']); exit; }

    $members = $group['members'] ?? [];
    if (memberIndex($members, $caller['username']) !== -1) {
        echo json_encode(['success' => false, 'error' => 'Already a member of this group']); exit;
    }

    $total = count($members);
    if ($total >= 36) {
        echo json_encode(['success' => false, 'error' => 'Queue is full (max 36 members across all tiers)']); exit;
    }

    // Get display name from profile
    $displayName = $caller['username'];
    $profileGlob = glob(USERDATA_DIR . '/profiles/*-' . $caller['username'] . '.txt');
    if ($profileGlob) {
        $pd = json_decode(file_get_contents($profileGlob[0]), true);
        $displayName = $pd['display_name'] ?? $caller['username'];
    }

    $members[] = [
        'username'     => $caller['username'],
        'display_name' => $displayName,
        'tier'         => 'queued',
        'joined_date'  => date('Y-m-d'),
        'note'         => substr(trim($input['note'] ?? ''), 0, 200),
    ];
    $group['members'] = $members;
    writeGroup($recipient, $group);

    echo json_encode(['success' => true, 'tier' => 'queued', 'group' => publicGroup($group)]);
    exit;
}

// POST: leave — member removes themselves from a group
if ($method === 'POST' && $action === 'leave') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient = trim($input['recipient'] ?? '');
    if (!$recipient) { http_response_code(400); echo json_encode(['error' => 'recipient required']); exit; }

    $group = readGroup($recipient);
    if (!$group) { http_response_code(404); echo json_encode(['error' => 'Group not found']); exit; }

    $members = $group['members'] ?? [];
    $idx = memberIndex($members, $caller['username']);
    if ($idx === -1) { echo json_encode(['success' => false, 'error' => 'Not a member of this group']); exit; }

    array_splice($members, $idx, 1);
    $group['members'] = $members;
    writeGroup($recipient, $group);

    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

// POST: manage — recipient or admin promotes/demotes/removes a member
if ($method === 'POST' && $action === 'manage') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient  = trim($input['recipient'] ?? $caller['username']);
    $target     = trim($input['target_username'] ?? '');
    $op         = trim($input['op'] ?? '');  // set_tier | remove

    if (!$target || !$op) { http_response_code(400); echo json_encode(['error' => 'target_username and op required']); exit; }

    // Only recipient themselves or an admin may manage
    if ($caller['username'] !== $recipient && !hasRole($caller['username'], 'admin')) {
        http_response_code(403); echo json_encode(['error' => 'Only the recipient or an admin can manage this group']); exit;
    }

    $group = readGroup($recipient);
    if (!$group) { http_response_code(404); echo json_encode(['error' => 'Group not found']); exit; }

    $members = $group['members'] ?? [];
    $idx = memberIndex($members, $target);
    if ($idx === -1) { echo json_encode(['success' => false, 'error' => 'Member not found']); exit; }

    if ($op === 'remove') {
        array_splice($members, $idx, 1);
    } elseif ($op === 'set_tier') {
        $newTier = trim($input['tier'] ?? '');
        if (!in_array($newTier, ['active', 'standby', 'queued'])) {
            http_response_code(400); echo json_encode(['error' => 'tier must be active, standby, or queued']); exit;
        }
        // Enforce max 12 active members
        if ($newTier === 'active') {
            $activeCount = 0;
            foreach ($members as $i => $m) {
                if ($i !== $idx && ($m['tier'] ?? '') === 'active') $activeCount++;
            }
            if ($activeCount >= 12) {
                echo json_encode(['success' => false, 'error' => 'Active tier is full (max 12)']); exit;
            }
        }
        $members[$idx]['tier'] = $newTier;
    } else {
        http_response_code(400); echo json_encode(['error' => 'Unknown op']); exit;
    }

    $group['members'] = $members;
    writeGroup($recipient, $group);

    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . $action]);
