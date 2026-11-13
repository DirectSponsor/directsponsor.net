<?php
/**
 * NIP-65 relay list (kind 10002).
 *
 * Tells other Nostr clients where a DirectSponsor user's notes are, and
 * where mentions should be delivered. One list per user, signed with that
 * user's nostr_privkey. Replaceable: a newer event supersedes the old one.
 *
 * Marker (third tag value), per NIP-65:
 *   "write" — look here for this user's notes
 *   "read"  — deliver mentions here
 *   omitted — both
 *
 * relay.directsponsor.net is write-only. Notes are stored there, and outside
 * clients must not treat it as a place to deliver mentions: the relay is
 * closed to non-DS keys. The public relays in nostr-relays.php are both,
 * because posts are broadcast there and replies are read back from there.
 * purplepag.es is read-only. Many clients look it up specifically for relay
 * lists, so the kind 10002 is also broadcast there. Kind 1 notes are not.
 *
 * Callers that already define nostr_publish_ws() keep their own copy.
 * The CLI backfill (publish-relay-lists.php) uses the copy below.
 */

if (!function_exists('nostr_publish_ws')) {
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
}

function nostr_nip65_tags(array $externalRelays) {
    $tags = [
        ['r', 'wss://relay.directsponsor.net', 'write'],
    ];
    foreach ($externalRelays as $r) {
        $port = (int) ($r['port'] ?? 443);
        $url = (!empty($r['ssl']) ? 'wss://' : 'ws://') . $r['host'];
        if ($port !== 443 && $port !== 80) {
            $url .= ':' . $port;
        }
        $tags[] = ['r', $url];
    }
    $tags[] = ['r', 'wss://purplepag.es', 'read'];
    return $tags;
}

function nostr_nip65_hash(array $tags) {
    return md5(json_encode($tags));
}

function nostr_nip65_broadcast_targets(array $externalRelays) {
    $targets = [
        ['host' => '127.0.0.1', 'port' => 7777, 'ssl' => false],
    ];
    foreach ($externalRelays as $r) {
        $targets[] = $r;
    }
    $targets[] = ['host' => 'purplepag.es', 'port' => 443, 'ssl' => true];
    return $targets;
}

/**
 * Sign and publish a kind 10002 when the advertised list has changed.
 * On success, sets $profile['nostr_relay_list_hash']. Does not write the file.
 * Returns true only when at least one relay accepted the event.
 */
function nostr_publish_relay_list($privkey, array &$profile, array $externalRelays) {
    if ($privkey === '' || $privkey === null) return false;
    $tags = nostr_nip65_tags($externalRelays);
    $hash = nostr_nip65_hash($tags);
    if (($profile['nostr_relay_list_hash'] ?? '') === $hash) return false;

    $event = json_encode([
        'kind'       => 10002,
        'created_at' => time(),
        'tags'       => $tags,
        'content'    => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signed = shell_exec('/usr/bin/python3 /opt/strfry/nostr-sign.py sign '
        . escapeshellarg($privkey) . ' '
        . escapeshellarg($event) . ' 2>/dev/null');
    if (!$signed) return false;
    $signed = trim($signed);

    $published = false;
    foreach (nostr_nip65_broadcast_targets($externalRelays) as $r) {
        if (nostr_publish_ws($signed, $r['host'], (int) $r['port'], !empty($r['ssl']))) {
            $published = true;
        }
    }
    if ($published) {
        $profile['nostr_relay_list_hash'] = $hash;
    }
    return $published;
}
