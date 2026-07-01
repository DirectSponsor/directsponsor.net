<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');
define('GROUPS_DIR',   USERDATA_DIR . '/sponsorship-groups');
define('SLOT_VALUE_USD', 10);  // Each sponsorship slot = $10/month

require_once __DIR__ . '/jwt-verify.php';

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

function slotsFilled($members) {
    $total = 0;
    foreach ($members as $m) $total += (int)($m['slots'] ?? 1);
    return $total;
}

function publicGroup($group) {
    $members     = $group['members'] ?? [];
    $need        = (int)($group['monthly_need_usd'] ?? 0);
    $slotsTotal  = $need > 0 ? (int)floor($need / SLOT_VALUE_USD) : 0;
    $slotsFilled = slotsFilled($members);
    $isFull      = $slotsTotal > 0 && $slotsFilled >= $slotsTotal;

    $out = [
        'recipient_username' => $group['recipient_username'],
        'description'        => $group['description'] ?? '',
        'monthly_need_usd'   => $need,
        'slots_total'        => $slotsTotal,
        'slots_filled'       => $slotsFilled,
        'is_full'            => $isFull,
        'created_date'       => $group['created_date'] ?? '',
        'members'            => [],
    ];
    foreach ($members as $m) {
        $out['members'][] = [
            'username'     => $m['username'],
            'display_name' => $m['display_name'] ?? $m['username'],
            'slots'        => (int)($m['slots'] ?? 1),
            'joined_date'  => $m['joined_date'],
            'last_paid'      => $m['last_paid'] ?? null,
            'last_paid_month' => $m['last_paid_month'] ?? null,
        ];
    }
    return $out;
}

function waitlistPath() {
    return GROUPS_DIR . '/_waitlist.json';
}

function readWaitlist() {
    $path = waitlistPath();
    if (!file_exists($path)) return ['members' => []];
    return json_decode(file_get_contents($path), true) ?? ['members' => []];
}

function writeWaitlist($data) {
    if (!is_dir(GROUPS_DIR)) mkdir(GROUPS_DIR, 0755, true);
    file_put_contents(waitlistPath(), json_encode($data, JSON_PRETTY_PRINT));
}

function waitlistIndex($members, $username) {
    foreach ($members as $i => $m) {
        if (($m['username'] ?? '') === $username) return $i;
    }
    return -1;
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
// Optional: ?filter=open  → only groups with slots available
if ($method === 'GET' && $action === 'list') {
    if (!is_dir(GROUPS_DIR)) { echo json_encode(['groups' => []]); exit; }
    $filter = trim($_GET['filter'] ?? '');
    $groups = [];
    foreach (glob(GROUPS_DIR . '/[!_]*.json') as $file) {  // exclude _waitlist.json
        $g = json_decode(file_get_contents($file), true);
        if (!$g) continue;
        $pg = publicGroup($g);
        if ($filter === 'open' && $pg['is_full']) continue;
        $groups[] = $pg;
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

// GET: check_payment — poll for sponsorship payment status
if ($method === 'GET' && $action === 'check_payment') {
    $payment_id  = trim($_GET['payment_id'] ?? '');
    if (!$payment_id) { http_response_code(400); echo json_encode(['error' => 'payment_id required']); exit; }
    $pendingFile = USERDATA_DIR . '/data/sponsorship-payments-pending/pending.json';
    if (file_exists($pendingFile)) {
        $pending = json_decode(file_get_contents($pendingFile), true) ?: [];
        foreach ($pending as $p) {
            if ($p['payment_id'] === $payment_id) { echo json_encode(['status' => 'pending']); exit; }
        }
    }
    echo json_encode(['status' => 'paid']);
    exit;
}

// GET: global waitlist
if ($method === 'GET' && $action === 'waitlist_get') {
    $wl = readWaitlist();
    echo json_encode(['count' => count($wl['members'] ?? []), 'members' => $wl['members'] ?? []]);
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

    if (isset($input['description']))     $group['description']     = substr(trim($input['description']), 0, 500);
    if (isset($input['monthly_need_usd'])) $group['monthly_need_usd'] = max(0, (int)$input['monthly_need_usd']);

    writeGroup($recipient, $group);
    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

// POST: join — logged-in user joins a recipient's queue
// Body: { recipient, slots (optional, default 1), note (optional), jwt }
if ($method === 'POST' && $action === 'join') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient   = trim($input['recipient'] ?? '');
    $wantedSlots = max(1, (int)($input['slots'] ?? 1));

    if (!$recipient) { http_response_code(400); echo json_encode(['error' => 'recipient required']); exit; }
    if ($caller['username'] === $recipient) {
        http_response_code(400); echo json_encode(['error' => 'You cannot join your own group']); exit;
    }

    $group = readGroup($recipient);
    if (!$group) { http_response_code(404); echo json_encode(['error' => 'No sponsorship group found for this recipient']); exit; }

    $members = $group['members'] ?? [];
    if (memberIndex($members, $caller['username']) !== -1) {
        echo json_encode(['success' => false, 'error' => 'Already in this group']); exit;
    }

    // Check slot availability (soft check — queue allowed even when full)
    $need       = (int)($group['monthly_need_usd'] ?? 0);
    $slotsTotal = $need > 0 ? (int)floor($need / SLOT_VALUE_USD) : 0;
    $filled     = slotsFilled($members);
    $available  = $slotsTotal > 0 ? max(0, $slotsTotal - $filled) : PHP_INT_MAX;

    // Cap wanted slots to what's actually available (don't overfill)
    $actualSlots = ($available > 0) ? min($wantedSlots, $available) : 0;
    // Allow joining queue even if full (actualSlots = 0 means queued, waiting)
    if ($actualSlots < 1) $actualSlots = 0;

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
        'slots'        => $actualSlots,   // 0 = in queue but no slot yet
        'joined_date'  => date('Y-m-d'),
        'note'         => substr(trim($input['note'] ?? ''), 0, 200),
    ];
    $group['members'] = $members;
    writeGroup($recipient, $group);

    $status = $actualSlots > 0 ? 'reserved' : 'queued';
    echo json_encode(['success' => true, 'status' => $status, 'slots' => $actualSlots, 'group' => publicGroup($group)]);
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

// POST: manage — recipient or admin adjusts slots or removes a member
// Body: { recipient, target_username, op: "set_slots"|"remove", slots?, jwt }
if ($method === 'POST' && $action === 'manage') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient = trim($input['recipient'] ?? $caller['username']);
    $target    = trim($input['target_username'] ?? '');
    $op        = trim($input['op'] ?? '');

    if (!$target || !$op) { http_response_code(400); echo json_encode(['error' => 'target_username and op required']); exit; }

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
    } elseif ($op === 'set_slots') {
        $newSlots = max(0, (int)($input['slots'] ?? 1));
        $members[$idx]['slots'] = $newSlots;
    } else {
        http_response_code(400); echo json_encode(['error' => 'Unknown op: ' . $op]); exit;
    }

    $group['members'] = $members;
    writeGroup($recipient, $group);

    echo json_encode(['success' => true, 'group' => publicGroup($group)]);
    exit;
}

// POST: pay — sponsor pays their monthly commitment
// Body: { recipient, amount_sats, jwt }
if ($method === 'POST' && $action === 'pay') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $recipient   = trim($input['recipient'] ?? '');
    $month = trim($input['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        http_response_code(400); echo json_encode(['error' => 'month required (YYYY-MM)']); exit;
    }
    if (!$recipient) { http_response_code(400); echo json_encode(['error' => 'recipient required']); exit; }
    if ($caller['username'] === $recipient) { http_response_code(400); echo json_encode(['error' => 'Cannot pay yourself']); exit; }

    // Server-side: month must be current or next only (no >1 month ahead)
    $currentMonth = date('Y-m');
    $nextMonth    = date('Y-m', strtotime('first day of next month'));
    if ($month !== $currentMonth && $month !== $nextMonth) {
        http_response_code(400); echo json_encode(['error' => 'Can only pay for current or next month']); exit;
    }

    // Verify caller has slots in this group
    $group = readGroup($recipient);
    if (!$group) { http_response_code(404); echo json_encode(['error' => 'Group not found']); exit; }
    $members = $group['members'] ?? [];
    $idx     = memberIndex($members, $caller['username']);
    if ($idx === -1) { http_response_code(403); echo json_encode(['error' => 'Not a member of this group']); exit; }
    $slots = (int)($members[$idx]['slots'] ?? 0);
    if ($slots < 1) { http_response_code(400); echo json_encode(['error' => 'No slots assigned — ask the recipient to assign your slots first']); exit; }

    // Check not already paid for this month
    foreach (($members[$idx]['payments'] ?? []) as $p) {
        if (($p['month'] ?? '') === $month) {
            http_response_code(400); echo json_encode(['error' => 'Already paid for ' . $month]); exit;
        }
    }

    // Get recipient Coinos API key from profile
    $recipientSlug   = preg_replace('/[^a-z0-9_\-]/', '', strtolower($recipient));
    $recipientGlob   = glob(USERDATA_DIR . '/profiles/*-' . $recipientSlug . '.txt');
    if (!$recipientGlob) { http_response_code(422); echo json_encode(['error' => 'Recipient profile not found']); exit; }
    $rp     = json_decode(file_get_contents($recipientGlob[0]), true);
    $apiKey = $rp['coinos_api_key'] ?? null;
    if (!$apiKey) { http_response_code(422); echo json_encode(['error' => 'Recipient has no payment key configured']); exit; }

    // Compute correct amount server-side: slots × $10 USD converted to sats
    // Client-supplied amount_sats is ignored — server controls the amount
    $usdOwed = $slots * 10;
    $priceCurl = curl_init();
    curl_setopt_array($priceCurl, [
        CURLOPT_URL            => 'https://mempool.space/api/v1/prices',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $priceResp = curl_exec($priceCurl);
    curl_close($priceCurl);
    if (!$priceResp) {
        http_response_code(503); echo json_encode(['error' => 'Could not fetch BTC price — try again in a moment']); exit;
    }
    $priceData = json_decode($priceResp, true);
    $btcUsd = (float)($priceData['USD'] ?? 0);
    if ($btcUsd < 1000) {
        http_response_code(503); echo json_encode(['error' => 'BTC price unavailable — try again in a moment']); exit;
    }
    $amount_sats = (int)round($usdOwed / $btcUsd * 100000000);

    // Sponsor display name
    $displayName = $caller['username'];
    $spGlob = glob(USERDATA_DIR . '/profiles/*-' . $caller['username'] . '.txt');
    if ($spGlob) {
        $sp = json_decode(file_get_contents($spGlob[0]), true);
        $displayName = $sp['display_name'] ?? $caller['username'];
    }

    $memo  = 'Sponsorship ' . $month . ' — ' . $displayName . ' (' . $slots . ' slot' . ($slots > 1 ? 's' : '') . ')';

    $invoice_data = ['invoice' => [
        'amount'  => $amount_sats,  // server-calculated: slots × $10 in sats
        'type'    => 'lightning',
        'memo'    => $memo,
        'webhook' => 'https://directsponsor.net/webhook.php',
        'secret'  => 'directsponsor_webhook_secret_2025',
    ]];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://coinos.io/api/invoice',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($invoice_data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($curl);
    curl_close($curl);

    if ($curlErr || $httpCode !== 200) {
        http_response_code(502); echo json_encode(['error' => 'Payment service unavailable']); exit;
    }
    $invoice = json_decode($response, true);
    if (!$invoice || !isset($invoice['text'])) {
        http_response_code(502); echo json_encode(['error' => 'Invalid invoice response']); exit;
    }

    $payment_id  = uniqid('sp_');
    $pendingDir  = USERDATA_DIR . '/data/sponsorship-payments-pending';
    if (!is_dir($pendingDir)) mkdir($pendingDir, 0755, true);
    $pendingFile = $pendingDir . '/pending.json';
    $pending     = file_exists($pendingFile) ? (json_decode(file_get_contents($pendingFile), true) ?: []) : [];
    $pending[] = [
        'payment_id'       => $payment_id,
        'type'             => 'sponsorship_payment',
        'recipient'        => $recipient,
        'sponsor_username' => $caller['username'],
        'sponsor_name'     => $displayName,
        'slots'            => $slots,
        'amount_sats'      => $amount_sats,
        'month'            => $month,
        'payment_hash'     => $invoice['paymentHash'] ?? $invoice['hash'] ?? '',
        'invoice'          => $invoice['text'],
        'created_at'       => date('Y-m-d H:i:s'),
    ];
    file_put_contents($pendingFile, json_encode($pending, JSON_PRETTY_PRINT));

    echo json_encode([
        'success'      => true,
        'payment_id'   => $payment_id,
        'invoice'      => $invoice['text'],
        'payment_hash' => $invoice['paymentHash'] ?? $invoice['hash'] ?? '',
        'amount_sats'  => $amount_sats,
        'qr_code'      => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($invoice['text']),
    ]);
    exit;
}

// POST: waitlist_join — join the site-wide waitlist for when any slot opens
if ($method === 'POST' && $action === 'waitlist_join') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $wl  = readWaitlist();
    $idx = waitlistIndex($wl['members'], $caller['username']);
    if ($idx !== -1) { echo json_encode(['success' => false, 'error' => 'Already on the waitlist']); exit; }

    $displayName = $caller['username'];
    $profileGlob = glob(USERDATA_DIR . '/profiles/*-' . $caller['username'] . '.txt');
    if ($profileGlob) {
        $pd = json_decode(file_get_contents($profileGlob[0]), true);
        $displayName = $pd['display_name'] ?? $caller['username'];
    }

    $wl['members'][] = [
        'username'     => $caller['username'],
        'display_name' => $displayName,
        'joined_date'  => date('Y-m-d'),
    ];
    writeWaitlist($wl);
    echo json_encode(['success' => true, 'count' => count($wl['members'])]);
    exit;
}

// POST: waitlist_leave — remove from site-wide waitlist
if ($method === 'POST' && $action === 'waitlist_leave') {
    $caller = getCallerFromJwt($input);
    if (!$caller) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit; }

    $wl  = readWaitlist();
    $idx = waitlistIndex($wl['members'], $caller['username']);
    if ($idx === -1) { echo json_encode(['success' => false, 'error' => 'Not on the waitlist']); exit; }

    array_splice($wl['members'], $idx, 1);
    writeWaitlist($wl);
    echo json_encode(['success' => true, 'count' => count($wl['members'])]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . $action]);
