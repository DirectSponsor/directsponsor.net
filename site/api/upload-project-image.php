<?php
// upload-project-image.php - Handle project image uploads
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check if it's a POST request with file upload
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    echo json_encode(['success' => false, 'error' => 'No image file provided']);
    exit;
}

$projectId = $_POST['project_id'] ?? '';
$username = $_POST['username'] ?? '';

if (empty($projectId)) {
    echo json_encode(['success' => false, 'error' => 'Project ID required']);
    exit;
}

if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Username required']);
    exit;
}

// Clean username (remove user_id prefix if present)
$cleanUsername = (strpos($username, '-') !== false) ? explode('-', $username)[1] : $username;

$uploadedFile = $_FILES['image'];

// Validate upload
if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (exceeds server limit of 2MB).',
        UPLOAD_ERR_FORM_SIZE  => 'File too large (exceeds form limit).',
        UPLOAD_ERR_PARTIAL    => 'Upload incomplete. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file received.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (no temp dir).',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the file.',
    ];
    $msg = $phpErrors[$uploadedFile['error']] ?? 'Upload failed (error ' . $uploadedFile['error'] . ').';
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Validate file size (max 2MB)
if ($uploadedFile['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum 2MB allowed.']);
    exit;
}

// Validate file type - Accept JPG, PNG, and WebP
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Please upload a JPG, PNG, WebP, or GIF image file.']);
    exit;
}

// Get file extension from mime type
$extensions = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif'
];
$extension = $extensions[$mimeType] ?? 'jpg';

// Create upload directory in user's protected data directory
$uploadDir = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/images/";
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

// Convert to WebP (except GIF) using GD — 65% quality, max 1200px wide
$convertToWebp = ($mimeType !== 'image/gif') && function_exists('imagewebp');

if ($convertToWebp) {
    $src = null;
    if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
        $src = imagecreatefromjpeg($uploadedFile['tmp_name']);
    } elseif ($mimeType === 'image/png') {
        $src = imagecreatefrompng($uploadedFile['tmp_name']);
    } elseif ($mimeType === 'image/webp') {
        $src = imagecreatefromwebp($uploadedFile['tmp_name']);
    }

    if ($src) {
        $origW = imagesx($src);
        $origH = imagesy($src);
        $maxW  = 1200;
        if ($origW > $maxW) {
            $newW = $maxW;
            $newH = (int)round($origH * $maxW / $origW);
        } else {
            $newW = $origW;
            $newH = $origH;
        }
        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);

        $filename   = $projectId . '.webp';
        $targetPath = $uploadDir . $filename;
        $ok = imagewebp($dst, $targetPath, 65);
        imagedestroy($dst);

        if ($ok) {
            $imageUrl = "/project-images/{$cleanUsername}/images/{$filename}";
            echo json_encode(['success' => true, 'image_url' => $imageUrl, 'filename' => $filename]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to encode image as WebP']);
        }
        exit;
    }
}

// Fallback: save original file as-is (GIF, or if GD WebP unavailable)
$filename   = $projectId . '.' . $extension;
$targetPath = $uploadDir . $filename;
error_log('DEBUG upload: projectId=' . $projectId . '; filename=' . $filename . '; targetPath=' . $targetPath);
if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
    $imageUrl = "/project-images/{$cleanUsername}/images/{$filename}";
    echo json_encode(['success' => true, 'image_url' => $imageUrl, 'filename' => $filename]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
}
?>