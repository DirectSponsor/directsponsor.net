<?php
/**
 * fundraiser.php — serves fundraiser.html with per-fundraiser Open Graph meta tags.
 * Rewrites og:title, og:description, og:image from the fundraiser's comment-tag data
 * so social media previews show the correct title and uploaded image.
 *
 * Apache rewrites fundraiser.html?project=X&user=Y here transparently (.htaccess).
 */

$project = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['project'] ?? '');
$user    = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['user']    ?? '');

$defaultTitle = 'Project Fundraiser - DirectSponsor';
$defaultDesc  = 'Support this project directly via Bitcoin Lightning. 100% goes to the recipient.';
$defaultImage = 'https://directsponsor.net/images/og/og-home.png';

$ogTitle = $defaultTitle;
$ogDesc  = $defaultDesc;
$ogImage = $defaultImage;
$ogUrl   = 'https://directsponsor.net/fundraiser.html'
         . ($project ? '?project=' . urlencode($project) . ($user ? '&user=' . urlencode($user) : '') : '');

if ($project && $user) {
    $base  = '/var/www/directsponsor.net/userdata/projects/' . $user . '/';
    $paths = [
        $base . 'active/'    . $project . '.html',
        $base . 'completed/' . $project . '.html',
    ];
    foreach ($paths as $path) {
        if (!file_exists($path)) continue;
        $html = file_get_contents($path);

        if (preg_match('/<!-- title -->(.*?)<!-- end title -->/s', $html, $m)) {
            $t = trim(strip_tags($m[1]));
            if ($t) $ogTitle = $t . ' - DirectSponsor';
        }
        foreach (['short-description', 'description'] as $dtag) {
            if (preg_match('/<!-- ' . $dtag . ' -->(.*?)<!-- end ' . $dtag . ' -->/s', $html, $m)) {
                $d = trim(strip_tags($m[1]));
                if ($d) { $ogDesc = mb_strimwidth($d, 0, 200, '…'); break; }
            }
        }
        if (preg_match('/<!-- image-url -->(.*?)<!-- end image-url -->/', $html, $m)) {
            $img = trim($m[1]);
            if ($img) {
                if (str_starts_with($img, '/')) $img = 'https://directsponsor.net' . $img;
                $ogImage = $img;
            }
        }
        break;
    }
}

$page = file_get_contents(__DIR__ . '/fundraiser.html');

$page = str_replace(
    '<meta property="og:title" content="Project Fundraiser - DirectSponsor">',
    '<meta property="og:title" content="' . htmlspecialchars($ogTitle, ENT_QUOTES) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:description" content="Support this project directly via Bitcoin Lightning. 100% goes to the recipient.">',
    '<meta property="og:description" content="' . htmlspecialchars($ogDesc, ENT_QUOTES) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:url" content="https://directsponsor.net">',
    '<meta property="og:url" content="' . htmlspecialchars($ogUrl, ENT_QUOTES) . '">',
    $page
);
$page = str_replace(
    '<meta property="og:image" content="https://directsponsor.net/images/og/og-home.png">',
    '<meta property="og:image" content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '">',
    $page
);
$page = str_replace(
    '<meta name="twitter:image" content="https://directsponsor.net/images/og/og-home.png">',
    '<meta name="twitter:image" content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '">',
    $page
);
$page = str_replace(
    '<title>Project Fundraiser - DirectSponsor</title>',
    '<title>' . htmlspecialchars($ogTitle, ENT_QUOTES) . '</title>',
    $page
);

header('Content-Type: text/html; charset=UTF-8');
echo $page;
