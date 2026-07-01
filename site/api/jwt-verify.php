<?php
/**
 * jwt-verify.php — shared JWT HMAC-SHA256 verification for all DS API endpoints.
 *
 * Reads the JWT secret from /etc/ds-jwt-secret (server-only, never in git).
 * The auth server (auth.directsponsor.org) signs tokens with the same secret.
 *
 * Usage:
 *   require_once __DIR__ . '/jwt-verify.php';
 *   $caller = getCallerFromJwt($input);   // returns ['username' => ..., 'user_id' => ...] or null
 *   $payload = verifyJwt($jwtString);     // returns payload array or false
 */

function _ds_jwt_secret() {
    static $s = null;
    if ($s !== null) return $s ?: null;
    $f = '/etc/ds-jwt-secret';
    if (!file_exists($f)) {
        error_log('DS JWT: secret file missing at ' . $f);
        return null;
    }
    $s = trim(file_get_contents($f));
    return $s ?: null;
}

function verifyJwt($jwt) {
    if (!$jwt) return false;
    $secret = _ds_jwt_secret();
    if (!$secret) return false;

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$h, $p, $sig] = $parts;

    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $h . '.' . $p, $secret, true)
    ), '+/', '-_'), '=');

    if (!hash_equals($expected, $sig)) return false;

    $payload = json_decode(base64_decode(
        str_pad(strtr($p, '-_', '+/'), strlen($p) % 4, '=', STR_PAD_RIGHT)
    ), true);
    if (!$payload) return false;

    if (isset($payload['exp']) && $payload['exp'] < time()) return false;

    return $payload;
}

/**
 * Extract and verify JWT from Authorization header or $input['jwt'].
 * Returns ['username' => ..., 'user_id' => ...] or null.
 */
function getCallerFromJwt($input = []) {
    $jwt = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) $jwt = $m[1];
    if (!$jwt && !empty($input['jwt'])) $jwt = $input['jwt'];
    if (!$jwt) return null;

    $payload = verifyJwt($jwt);
    if (!$payload) return null;

    $username = $payload['username'] ?? null;
    $userId   = $payload['user_id'] ?? $payload['sub'] ?? null;
    if (!$username || !$userId) return null;

    return ['username' => $username, 'user_id' => (string)$userId];
}
