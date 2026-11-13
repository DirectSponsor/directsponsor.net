<?php
/**
 * Backfill NIP-65 relay lists for users who already have a Nostr key.
 *
 * New and updated lists also go out from save-post.php and simple-profile.php.
 * Run this once on RN1 after deploy so existing keys publish without waiting
 * for the next post:
 *
 *   php /var/www/directsponsor.net/html/api/publish-relay-lists.php
 *
 * Refuses to run as a web request.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

define('USERDATA_DIR', getenv('DS_USERDATA') ?: '/var/www/directsponsor.net/userdata');

require __DIR__ . '/nostr-nip65.php';
$external = require __DIR__ . '/nostr-relays.php';
$expected = nostr_nip65_hash(nostr_nip65_tags($external));

$files = glob(USERDATA_DIR . '/profiles/*.txt');
if (!$files) {
    fwrite(STDERR, "No profiles in " . USERDATA_DIR . "/profiles\n");
    exit(1);
}

$published = 0;
$unchanged = 0;
$failed = 0;
$skipped = 0;

foreach ($files as $file) {
    $profile = json_decode(file_get_contents($file), true);
    if (!is_array($profile) || empty($profile['nostr_privkey'])) {
        $skipped++;
        continue;
    }
    $name = $profile['username'] ?? basename($file);
    if (nostr_publish_relay_list($profile['nostr_privkey'], $profile, $external)) {
        $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($file, $json) === false) {
            echo "published-but-not-saved $name\n";
            $failed++;
            continue;
        }
        echo "published $name\n";
        $published++;
        continue;
    }
    if (($profile['nostr_relay_list_hash'] ?? '') === $expected) {
        echo "unchanged $name\n";
        $unchanged++;
    } else {
        echo "failed $name\n";
        $failed++;
    }
}

echo "published=$published unchanged=$unchanged skipped=$skipped failed=$failed\n";
exit($failed > 0 ? 1 : 0);
