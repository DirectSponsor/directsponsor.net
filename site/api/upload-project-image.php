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
    echo json_encode(['success' => false, 'error' => 'Upload failed: ' . $uploadedFile['error']]);
    exit;
}

// Validate file size (max 2MB)
if ($uploadedFile['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum 2MB allowed.']);
    exit;
}

// Validate file type - Accept JPG, PNG, and WebP
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Please upload a JPG, PNG, or WebP image file.']);
    exit;
}

// Get file extension from mime type
$extensions = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
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

// Use simple project ID as filename
$filename = $projectId . '.' . $extension;
$targetPath = $uploadDir . $filename;

error_log('DEBUG upload: projectId=' . $projectId);
error_log('DEBUG upload: filename=' . $filename);
error_log('DEBUG upload: targetPath=' . $targetPath);

// File will be automatically overwritten if it exists

// Move uploaded file
if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
    // Return the web-accessible URL via Apache Alias
    $imageUrl = "/project-images/{$cleanUsername}/images/{$filename}";
    
    echo json_encode([
        'success' => true,
        'image_url' => $imageUrl,
        'filename' => $filename
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
}
?>