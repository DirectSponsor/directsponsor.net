<?php
// Shared Nostr external relay list
// Used by save-post.php and simple-profile.php for outbound event broadcasting.
// Add/remove relays here — only one place to maintain.
// Relays marked as comments are ready to enable when needed.

return [
    ['host' => 'relay.damus.io',   'port' => 443, 'ssl' => true],
    ['host' => 'relay.primal.net', 'port' => 443, 'ssl' => true],
    ['host' => 'nos.lol',          'port' => 443, 'ssl' => true],
    // ['host' => 'nostr.wine',       'port' => 443, 'ssl' => true],  // enable when ready
];