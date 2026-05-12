<?php
// Group page — PHP-rendered from JSON, minimal layout
// URL: /group.php?recipient=USERNAME

define('USERDATA_DIR', '/var/www/directsponsor.net/userdata');
define('GROUPS_DIR',   USERDATA_DIR . '/sponsorship-groups');

$recipient = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($_GET['recipient'] ?? '')));
if (!$recipient) {
    header('Location: sponsorships.html');
    exit;
}

$groupFile = GROUPS_DIR . '/' . $recipient . '.json';
$group     = file_exists($groupFile) ? json_decode(file_get_contents($groupFile), true) : null;

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function fmtMonth($ym) {
    if (!$ym) return '';
    $p = explode('-', $ym);
    $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    return ($months[(int)$p[1] - 1] ?? '') . ' ' . $p[0];
}

// Grace window: days 1-5 = current month due; day 6+ = next month due
$today     = (int)date('j');
$dueMonth  = $today <= 5 ? date('Y-m') : date('Y-m', strtotime('first day of next month'));

$members     = $group ? ($group['members'] ?? []) : [];
$need        = $group ? (int)($group['monthly_need_usd'] ?? 0) : 0;
$slotsTotal  = $need > 0 ? (int)floor($need / 10) : 0;
$slotsFilled = 0;
foreach ($members as $m) $slotsFilled += (int)($m['slots'] ?? 0);
$pct = $slotsTotal > 0 ? min(100, round($slotsFilled / $slotsTotal * 100)) : 0;

$title = esc($recipient) . "'s Sponsorship Group – DirectSponsor";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>(function(){var t=localStorage.getItem('ds-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="preload" href="styles/directsponsor-v2.css" as="style">
    <link rel="stylesheet" href="styles/directsponsor-v2.css">
    <script>
    (function() {
        var params = new URLSearchParams(window.location.search);
        var jwt = params.get('jwt');
        if (jwt) {
            sessionStorage.setItem('jwt', jwt);
            params.delete('jwt');
            var newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', newUrl);
        }
    })();
    </script>
    <style>
    .grp-header {
        display: table; width: 100%; border-collapse: collapse;
        padding: 0.6em 1em; background: var(--bg-card);
        border-bottom: 1px solid var(--border); box-sizing: border-box;
    }
    .grp-header-left  { display: table-cell; vertical-align: middle; }
    .grp-header-right { display: table-cell; vertical-align: middle; text-align: right; }
    .grp-header a     { color: var(--link); text-decoration: none; font-size: 0.9em; }
    .grp-header .logo { font-weight: bold; font-size: 1em; margin-right: 1em; }
    .grp-wrap         { max-width: 46em; margin: 1.5em auto; padding: 0 1em; }
    .grp-card         { background: var(--bg-card); border: 1px solid var(--border); border-radius: 0.4em; padding: 1em 1.2em; margin-bottom: 1em; }
    .grp-progress-bar { width: 100%; background: var(--border); border-radius: 0.2em; height: 0.6em; overflow: hidden; margin: 0.4em 0 0.2em; }
    .grp-progress-fill { height: 100%; background: #f7b731; border-radius: 0.2em; }
    .grp-table        { width: 100%; border-collapse: collapse; font-size: 0.88em; }
    .grp-table th     { text-align: left; padding: 0.35em 0.5em; border-bottom: 2px solid var(--border); color: var(--text-muted); font-weight: normal; }
    .grp-table td     { padding: 0.35em 0.5em; border-bottom: 1px solid var(--border); vertical-align: top; }
    .grp-table tr:last-child td { border-bottom: none; }
    .paid-yes         { color: #2a9d2a; font-weight: bold; }
    .paid-no          { color: var(--text-muted); }
    .tier-active      { color: var(--text); }
    .tier-queued      { color: var(--text-muted); font-style: italic; }
    .pay-hist         { font-size: 0.82em; color: var(--text-muted); margin: 0.2em 0 0; }
    .pay-hist summary { cursor: pointer; color: var(--link); }
    .pay-hist ul      { margin: 0.3em 0 0 1em; padding: 0; list-style: disc; }
    .pay-hist li      { margin-bottom: 0.1em; }
    .action-panel     { display: none; }
    .save-btn         { background: #f7b731; color: #000; border: none; cursor: pointer; padding: 0.4em 1em; border-radius: 0.3em; font-size: 0.9em; font-weight: bold; }
    .save-btn:hover   { background: #e0a520; }
    .save-btn:disabled { background: var(--border); color: var(--text-muted); cursor: default; }
    .btn-small        { font-size: 0.8em; padding: 0.2em 0.5em; background: var(--bg-page); border: 1px solid var(--border); border-radius: 0.2em; cursor: pointer; color: var(--text); }
    .btn-small:hover  { background: var(--border); }
    .status-msg       { font-size: 0.85em; color: var(--text-muted); margin-left: 0.5em; }
    .status-msg.error { color: #c0392b; }
    .field-hint       { font-size: 0.8em; color: var(--text-muted); margin: 0.2em 0 0; }
    .form-row         { margin-bottom: 0.75em; }
    .form-row label   { display: block; font-size: 0.85em; color: var(--text-muted); margin-bottom: 0.2em; }
    @media (max-width: 40em) {
        .grp-table th:nth-child(4),
        .grp-table td:nth-child(4) { display: none; }
    }
    </style>
</head>
<body>

<div class="grp-header">
    <div class="grp-header-left">
        <a href="index.html" class="logo">💎 DirectSponsor</a>
        <a href="sponsorships.html">← All groups</a>
    </div>
    <div class="grp-header-right">
        <button onclick="dsToggleTheme()" title="Toggle dark mode" style="background:none;border:none;cursor:pointer;font-size:1em;color:var(--link);" id="theme-toggle">🌙</button>
    </div>
</div>

<div class="grp-wrap">

<?php if (!$group): ?>
<div class="grp-card">
    <h1>Group not found</h1>
    <p class="color-muted">No sponsorship group exists for <strong><?= esc($recipient) ?></strong>.</p>
    <p><a href="sponsorships.html">← Browse all groups</a></p>
</div>
<?php else: ?>

<!-- Group header card -->
<div class="grp-card">
    <h1 style="margin:0 0 0.2em;"><?= esc($recipient) ?>'s Sponsorship Group</h1>
    <?php if (!empty($group['description'])): ?>
    <p style="margin:0 0 0.75em;color:var(--text-muted);"><?= esc($group['description']) ?></p>
    <?php endif; ?>

    <?php if ($slotsTotal > 0): ?>
    <div class="grp-progress-bar"><div class="grp-progress-fill" style="width:<?= $pct ?>%;"></div></div>
    <p class="color-muted text-small" style="margin:0 0 0.5em;">
        <?= $slotsFilled ?> of <?= $slotsTotal ?> slots filled
        ($<?= $slotsFilled * 10 ?> of $<?= $slotsTotal * 10 ?>/month)
        <?php
        $queueCount = count(array_filter($members, fn($m) => ($m['slots'] ?? 0) == 0));
        if ($queueCount) echo ' &mdash; ' . $queueCount . ' queued';
        ?>
    </p>
    <?php endif; ?>

    <!-- Action panel shown by JS based on JWT role -->
    <div id="action-join" class="action-panel">
        <p style="margin:0.5em 0 0.25em;">
            <select id="join-slots" style="font-size:0.9em;margin-right:0.5em;">
                <option value="">Select slots…</option>
                <?php
                $avail = max(1, $slotsTotal - $slotsFilled);
                for ($s = 1; $s <= $avail; $s++) {
                    echo '<option value="' . $s . '">$' . ($s*10) . '/month (' . $s . ' slot' . ($s>1?'s':'') . ')</option>';
                }
                ?>
            </select>
            <button class="save-btn" onclick="doJoin()">Commit &amp; join</button>
            <span id="join-status" class="status-msg"></span>
        </p>
    </div>
    <div id="action-queued" class="action-panel">
        <p class="color-muted text-small" style="margin:0.5em 0 0;">⏳ You are in the queue. The recipient will assign slots when one opens.</p>
        <button class="btn-small" style="margin-top:0.3em;" onclick="doLeave()">Leave queue</button>
    </div>
    <div id="action-login" class="action-panel">
        <p style="margin:0.5em 0 0;">
            <a href="#" onclick="doLogin(); return false;" class="save-btn" style="text-decoration:none;display:inline-block;">Log in to join</a>
        </p>
    </div>
    <div id="action-is-recipient" class="action-panel">
        <p class="color-muted text-small" style="margin:0.5em 0 0;">This is your group. Manage settings below.</p>
    </div>
</div>

<!-- Member list -->
<div class="grp-card">
    <h2 style="margin:0 0 0.75em;">Members</h2>
    <?php if (empty($members)): ?>
    <p class="color-muted text-small">No members yet.</p>
    <?php else: ?>
    <table class="grp-table">
        <thead>
            <tr>
                <th>Member</th>
                <th>Tier</th>
                <th><?= esc(fmtMonth($dueMonth)) ?></th>
                <th>Last paid</th>
                <th>History</th>
                <th id="manage-col-header" style="display:none;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m):
            $slots        = (int)($m['slots'] ?? 0);
            $lastPaidMonth = $m['last_paid_month'] ?? null;
            $lastPaidDate  = $m['last_paid'] ?? null;
            $payments      = $m['payments'] ?? [];
            $paidThisMonth = ($lastPaidMonth === $dueMonth);
            $uname         = $m['username'] ?? '';
        ?>
            <tr id="row-<?= esc($uname) ?>" data-username="<?= esc($uname) ?>" data-slots="<?= $slots ?>" data-last-paid-month="<?= esc($lastPaidMonth ?? '') ?>">
                <td><a href="profile.html?user=<?= urlencode($uname) ?>"><?= esc($m['display_name'] ?? $uname) ?></a></td>
                <td>
                    <?php if ($slots > 0): ?>
                    <span class="tier-active"><?= $slots ?> slot<?= $slots > 1 ? 's' : '' ?> ($<?= $slots * 10 ?>/mo)</span>
                    <?php else: ?>
                    <span class="tier-queued">Queued</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($slots > 0): ?>
                        <?php if ($paidThisMonth): ?>
                        <span class="paid-yes">✓ Paid</span>
                        <?php else: ?>
                        <span class="paid-no">— Not yet</span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="paid-no">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $lastPaidDate ? esc($lastPaidDate) : '<span class="color-muted">—</span>' ?></td>
                <td>
                    <?php if (!empty($payments)): ?>
                    <details class="pay-hist">
                        <summary><?= count($payments) ?> payment<?= count($payments) > 1 ? 's' : '' ?></summary>
                        <ul>
                        <?php foreach (array_reverse($payments) as $p): ?>
                            <li><?= esc(fmtMonth($p['month'] ?? '')) ?> — <?= number_format((int)($p['amount_sats'] ?? 0)) ?> sats</li>
                        <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php else: ?>
                    <span class="color-muted text-small">—</span>
                    <?php endif; ?>
                </td>
                <td class="manage-col" style="display:none;">
                    <input type="number" value="<?= $slots ?>" min="0" style="width:3em;text-align:center;font-size:0.82em;" onchange="setSlots('<?= esc($uname) ?>',this.value,this)" title="Slots">
                    <button class="btn-small" onclick="removeMember('<?= esc($uname) ?>',this)" title="Remove">✕</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Pay button row — shown by JS for active sponsor -->
    <div id="my-pay-row" style="display:none; margin-top:0.75em; padding-top:0.75em; border-top:1px solid var(--border);">
        <span id="my-pay-status"></span>
    </div>
</div>

<!-- Recipient management panel — shown by JS -->
<div id="recipient-panel" class="grp-card" style="display:none;">
    <h2 style="margin:0 0 0.75em;">⚙️ Group settings</h2>
    <div class="form-row">
        <label for="sg-description">Description</label>
        <textarea id="sg-description" rows="2" style="width:100%;box-sizing:border-box;"><?= esc($group['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
        <label for="sg-need-usd">Monthly income needed (USD)</label>
        <input type="number" id="sg-need-usd" value="<?= (int)($group['monthly_need_usd'] ?? 0) ?>" min="0" step="10" style="width:7em;">
        <p class="field-hint">$10 per slot — e.g. $60 = 6 slots.</p>
    </div>
    <button class="save-btn" onclick="saveSettings()">Save settings</button>
    <span id="sg-save-status" class="status-msg"></span>
</div>

<?php endif; ?>
</div><!-- /grp-wrap -->

<!-- Pay modal -->
<div id="sp-pay-modal" role="dialog" aria-modal="true" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:1000;overflow-y:auto;">
  <div style="background:var(--bg-card);border-radius:0.5em;max-width:22em;margin:3em auto;padding:1.5em;position:relative;">
    <button onclick="closePayModal()" style="position:absolute;top:0.5em;right:0.75em;background:none;border:none;font-size:1.2em;cursor:pointer;color:var(--text);">✕</button>
    <h2 id="sp-pay-title" style="margin-top:0;font-size:1.1em;">⚡ Pay this month</h2>
    <div id="sp-pay-loading"><p class="color-muted text-small">Getting exchange rate…</p></div>
    <div id="sp-pay-invoice" style="display:none;">
      <p id="sp-pay-desc" style="font-size:0.9em;margin:0 0 0.5em;"></p>
      <div id="sp-qr-container" style="text-align:center;margin:0.5em 0;"></div>
      <div id="sp-invoice-text" style="word-break:break-all;font-size:0.7em;color:var(--text-muted);margin:0.5em 0;"></div>
      <button onclick="copyInvoice()" id="sp-copy-btn" class="btn-small" style="margin-bottom:0.5em;">📋 Copy Invoice</button>
      <div id="sp-pay-status" style="margin-top:0.4em;font-size:0.9em;"></div>
      <button class="btn-small" onclick="closePayModal()" style="margin-top:1em;">Close</button>
    </div>
    <div id="sp-pay-error" style="display:none;">
      <p id="sp-error-msg" style="color:#c0392b;"></p>
      <button class="btn-small" onclick="closePayModal()">Close</button>
    </div>
  </div>
</div>

<script>
// ---- Theme ----
function dsToggleTheme() {
    var html = document.documentElement;
    var dark = html.getAttribute('data-theme') === 'dark';
    if (dark) { html.removeAttribute('data-theme'); localStorage.setItem('ds-theme','light'); document.getElementById('theme-toggle').textContent='🌙'; }
    else       { html.setAttribute('data-theme','dark'); localStorage.setItem('ds-theme','dark'); document.getElementById('theme-toggle').textContent='☀️'; }
}
(function(){ if(localStorage.getItem('ds-theme')==='dark'){ var b=document.getElementById('theme-toggle'); if(b) b.textContent='☀️'; } })();

// ---- Auth ----
var _jwt = sessionStorage.getItem('jwt') || localStorage.getItem('jwt');
var _me  = null;
try { if (_jwt) _me = JSON.parse(atob(_jwt.split('.')[1])).username || null; } catch(e){}

var _recipient = <?= json_encode($recipient) ?>;
var _dueMonth  = <?= json_encode($dueMonth) ?>;
var _members   = <?= json_encode(array_map(fn($m) => [
    'username'        => $m['username'] ?? '',
    'slots'           => (int)($m['slots'] ?? 0),
    'last_paid_month' => $m['last_paid_month'] ?? null,
], $members)) ?>;

function doLogin() {
    window.location.href = 'https://auth.directsponsor.org/jwt-login.php?redirect_uri='
        + encodeURIComponent(window.location.href.replace(/^http:/, 'https:'));
}

// ---- Personalise page on load ----
(function() {
    if (!_recipient) return;
    var isRecipient  = (_me === _recipient);
    var myEntry      = _me ? _members.find(function(m){ return m.username === _me; }) : null;
    var mySlots      = myEntry ? myEntry.slots : 0;
    var isQueued     = myEntry && mySlots === 0;
    var isActive     = myEntry && mySlots > 0;
    var isMember     = !!myEntry;

    // Show correct action panel in header card
    if (isRecipient) {
        show('action-is-recipient');
        show('recipient-panel');
        // Show manage columns in table
        document.querySelectorAll('.manage-col').forEach(function(el){ el.style.display=''; });
        var h = document.getElementById('manage-col-header');
        if (h) h.style.display = '';
    } else if (isActive) {
        var alreadyPaid = myEntry.last_paid_month === _dueMonth;
        var payRow = document.getElementById('my-pay-row');
        if (payRow) {
            payRow.style.display = 'block';
            if (alreadyPaid) {
                document.getElementById('my-pay-status').innerHTML =
                    '<span class="paid-yes">✓ You have paid for ' + fmtMonth(_dueMonth) + '</span>';
            } else {
                document.getElementById('my-pay-status').innerHTML =
                    '<button class="save-btn" onclick="openPayModal(' + mySlots + ')" style="padding:0.5em 1.2em;font-size:1em;">⚡ Pay for ' + fmtMonth(_dueMonth) + '</button>';
            }
        }
    } else if (isQueued) {
        show('action-queued');
    } else if (_me) {
        show('action-join');
    } else {
        show('action-login');
    }
})();

function show(id) { var el = document.getElementById(id); if (el) el.style.display = 'block'; }

function fmtMonth(ym) {
    var p = ym.split('-');
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    return months[parseInt(p[1])-1] + ' ' + p[0];
}

// ---- Join ----
function doJoin() {
    if (!_jwt) { doLogin(); return; }
    var slotsEl = document.getElementById('join-slots');
    var slots = slotsEl ? parseInt(slotsEl.value) : 0;
    if (!slots) { alert('Please select how many slots you want to commit.'); return; }
    var status = document.getElementById('join-status');
    status.textContent = 'Joining…';
    fetch('/api/sponsorship-api.php?action=join', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
        body: JSON.stringify({ recipient: _recipient, slots: slots, jwt: _jwt })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) { location.reload(); }
        else { status.textContent = d.error || 'Could not join.'; status.className = 'status-msg error'; }
    })
    .catch(function(){ status.textContent = 'Network error.'; status.className = 'status-msg error'; });
}

// ---- Leave ----
function doLeave() {
    if (!_jwt) return;
    if (!confirm('Leave this sponsorship group?')) return;
    fetch('/api/sponsorship-api.php?action=leave', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
        body: JSON.stringify({ recipient: _recipient, jwt: _jwt })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.success) location.reload(); else alert(d.error || 'Could not leave.'); })
    .catch(function(){ alert('Network error.'); });
}

// ---- Recipient: save settings ----
function saveSettings() {
    if (!_jwt) return;
    var desc    = (document.getElementById('sg-description').value || '').trim();
    var needUsd = Math.round((parseInt(document.getElementById('sg-need-usd').value) || 0) / 10) * 10;
    var status  = document.getElementById('sg-save-status');
    status.textContent = 'Saving…'; status.className = 'status-msg';
    fetch('/api/sponsorship-api.php?action=setup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
        body: JSON.stringify({ description: desc, monthly_need_usd: needUsd, jwt: _jwt })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) { status.textContent = '✅ Saved!'; status.className = 'status-msg'; }
        else { status.textContent = d.error || 'Save failed.'; status.className = 'status-msg error'; }
    })
    .catch(function(){ status.textContent = 'Network error.'; status.className = 'status-msg error'; });
}

// ---- Recipient: set slots ----
function setSlots(target, slots, inp) {
    if (!_jwt) return;
    var prev = inp.dataset.prev !== undefined ? inp.dataset.prev : inp.value;
    inp.disabled = true;
    fetch('/api/sponsorship-api.php?action=manage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
        body: JSON.stringify({ recipient: _recipient, target_username: target, op: 'set_slots', slots: parseInt(slots)||0, jwt: _jwt })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        inp.disabled = false;
        if (d.success) { inp.dataset.prev = slots; }
        else { alert(d.error || 'Could not update slots.'); inp.value = prev; }
    })
    .catch(function(){ inp.disabled = false; inp.value = prev; });
}

// ---- Recipient: remove member ----
function removeMember(target, btn) {
    if (!_jwt) return;
    if (!confirm('Remove ' + target + ' from the group?')) return;
    btn.disabled = true;
    fetch('/api/sponsorship-api.php?action=manage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
        body: JSON.stringify({ recipient: _recipient, target_username: target, op: 'remove', jwt: _jwt })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) { var row = document.getElementById('row-' + target); if (row) row.parentNode.removeChild(row); }
        else { btn.disabled = false; alert(d.error || 'Could not remove.'); }
    })
    .catch(function(){ btn.disabled = false; });
}

// ---- Pay modal ----
var _spPollTimer = null;
var _spConfirmed = false;
var _spInvoice   = '';

function openPayModal(slots) {
    _spConfirmed = false; _spInvoice = '';
    document.getElementById('sp-pay-title').textContent = '⚡ Pay for ' + fmtMonth(_dueMonth) + ' — ' + _recipient;
    document.getElementById('sp-pay-loading').style.display  = 'block';
    document.getElementById('sp-pay-invoice').style.display  = 'none';
    document.getElementById('sp-pay-error').style.display    = 'none';
    document.getElementById('sp-pay-status').textContent     = '';
    document.getElementById('sp-pay-modal').style.display    = 'block';

    fetch('https://mempool.space/api/v1/prices')
        .then(function(r){ return r.json(); })
        .then(function(prices){
            var btcUsd = prices.USD;
            if (!btcUsd) throw new Error('no rate');
            var usd  = slots * 10;
            var sats = Math.round((usd / btcUsd) * 100000000);
            document.getElementById('sp-pay-loading').innerHTML = '<p class="color-muted text-small">Creating invoice…</p>';
            return fetch('/api/sponsorship-api.php?action=pay', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _jwt },
                body: JSON.stringify({ recipient: _recipient, amount_sats: sats, month: _dueMonth, jwt: _jwt })
            }).then(function(r){ return r.json(); }).then(function(d){
                if (!d.success) { showPayError(d.error || 'Could not create invoice.'); return; }
                _spInvoice = d.invoice;
                document.getElementById('sp-pay-desc').innerHTML =
                    'Paying for: <strong>' + fmtMonth(_dueMonth) + '</strong> &mdash; <strong>' + sats.toLocaleString() + ' sats</strong> ($' + usd + ')';
                document.getElementById('sp-qr-container').innerHTML =
                    '<img src="' + d.qr_code + '" alt="Lightning QR code" width="220" height="220">';
                document.getElementById('sp-invoice-text').textContent = d.invoice;
                document.getElementById('sp-pay-loading').style.display = 'none';
                document.getElementById('sp-pay-invoice').style.display = 'block';
                document.getElementById('sp-pay-status').textContent    = 'Waiting for payment…';
                if (_spPollTimer) clearInterval(_spPollTimer);
                _spPollTimer = setInterval(function(){
                    fetch('/api/sponsorship-api.php?action=check_payment&payment_id=' + encodeURIComponent(d.payment_id))
                        .then(function(r){ return r.json(); })
                        .then(function(s){
                            if (s.status === 'paid') {
                                clearInterval(_spPollTimer);
                                _spConfirmed = true;
                                document.getElementById('sp-pay-status').innerHTML =
                                    '<strong>✅ Payment received! Thank you!</strong>';
                            }
                        }).catch(function(){});
                }, 3000);
            });
        })
        .catch(function(){ showPayError('Could not get exchange rate. Please try again.'); });
}

function showPayError(msg) {
    document.getElementById('sp-pay-loading').style.display = 'none';
    document.getElementById('sp-error-msg').textContent     = msg;
    document.getElementById('sp-pay-error').style.display   = 'block';
}

function copyInvoice() {
    var btn = document.getElementById('sp-copy-btn');
    navigator.clipboard.writeText(_spInvoice).then(function(){
        btn.textContent = '✅ Copied!';
        setTimeout(function(){ btn.textContent = '📋 Copy Invoice'; }, 2000);
    }).catch(function(){
        var ta = document.createElement('textarea');
        ta.value = _spInvoice; ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        btn.textContent = '✅ Copied!';
        setTimeout(function(){ btn.textContent = '📋 Copy Invoice'; }, 2000);
    });
}

function closePayModal() {
    if (_spPollTimer) { clearInterval(_spPollTimer); _spPollTimer = null; }
    document.getElementById('sp-pay-modal').style.display = 'none';
    if (_spConfirmed) location.reload();
}
</script>

</body>
</html>
