<?php
/**
 * post.php — serves posts.html with per-post Open Graph meta tags.
 * Apache rewrites posts.html?post_id= here (.htaccess), same pattern as fundraiser.php.
 * News posts fill the card from their title, description, and image.
 * Any other post uses its title, intro, and image so shared links are not the generic card.
 *
 * DS_USERDATA overrides the userdata path for local checks. Unset in production.
 */

$userdata = getenv('DS_USERDATA') ?: '/var/www/directsponsor.net/userdata';
$site = 'https://directsponsor.net';

$user   = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['user'] ?? ''));
$postId = preg_replace('/[^0-9]/', '', $_GET['post_id'] ?? '');

$defaultTitle = 'Posts - DirectSponsor';
$defaultDesc  = 'Updates and articles from the DirectSponsor community.';
$defaultImage = $site . '/images/og/og-posts.png';

$ogTitle = $defaultTitle;
$ogDesc  = $defaultDesc;
$ogImage = $defaultImage;
$ogType  = 'website';
$ogUrl   = $site;

if ($user && $postId) {
    $files = glob($userdata . '/posts/' . $user . '/' . $postId . '-*.json') ?: [];
    if ($files) {
        $post = json_decode(file_get_contents($files[0]), true) ?: [];
        $owner = preg_replace('/[^a-z0-9_-]/', '', strtolower($post['username'] ?? ''));
        if ($owner === $user) {
            $ogType = 'article';
            $ogUrl  = $site . '/posts.html?user=' . rawurlencode($user) . '&post_id=' . rawurlencode($postId);

            $title = ds_one_line($post['title'] ?? '');
            if ($title !== '') {
                $ogTitle = $title . ' - DirectSponsor';
            } else {
                $who = ds_display_name($userdata, $user);
                $ogTitle = $who . ' - update - DirectSponsor';
            }

            $desc = ds_one_line(strip_tags($post['intro'] ?? ''));
            if ($desc === '') $desc = ds_one_line(strip_tags($post['body'] ?? ''));
            if ($desc !== '') $ogDesc = ds_clip($desc, 200);

            $img = ds_abs_url($post['image_url'] ?? '', $site);
            if ($img === '') {
                $preview = $post['link_preview'] ?? null;
                if (is_array($preview) && empty($preview['blocked'])) {
                    $img = ds_abs_url($preview['image'] ?? '', $site);
                }
            }
            if ($img !== '') $ogImage = $img;
        }
    }
}

$page = file_get_contents(__DIR__ . '/posts.html');
$esc = function ($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$page = str_replace(
    '<title>' . $defaultTitle . '</title>',
    '<title>' . $esc($ogTitle) . '</title>',
    $page
);
$page = str_replace(
    '<meta name="description" content="' . $defaultDesc . '">',
    '<meta name="description" content="' . $esc($ogDesc) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:type" content="website">',
    '<meta property="og:type" content="' . $ogType . '">',
    $page
);
$page = str_replace(
    '<meta property="og:url" content="' . $site . '">',
    '<meta property="og:url" content="' . $esc($ogUrl) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:title" content="' . $defaultTitle . '">',
    '<meta property="og:title" content="' . $esc($ogTitle) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:description" content="' . $defaultDesc . '">',
    '<meta property="og:description" content="' . $esc($ogDesc) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:image" content="' . $defaultImage . '">',
    '<meta property="og:image" content="' . $esc($ogImage) . '">',
    $page
);
$page = str_replace(
    '<meta name="twitter:image" content="' . $defaultImage . '">',
    '<meta name="twitter:image" content="' . $esc($ogImage) . '">',
    $page
);

header('Content-Type: text/html; charset=UTF-8');
echo $page;

function ds_one_line($s) {
    $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s;
}

function ds_clip($s, $max) {
    $n = preg_match_all('/./u', $s, $m);
    if ($n === false || $n <= $max) return $s;
    preg_match('/^.{0,' . ($max - 1) . '}/u', $s, $cut);
    return rtrim($cut[0]) . '…';
}

function ds_abs_url($url, $site) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if ($url[0] === '/') return $site . $url;
    return '';
}

function ds_display_name($userdata, $username) {
    $files = glob($userdata . '/profiles/*-' . $username . '.txt') ?: [];
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data || strtolower($data['username'] ?? '') !== $username) continue;
        $name = ds_one_line(strip_tags($data['display_name'] ?? ''));
        if ($name !== '') return $name;
    }
    return $username;
}
