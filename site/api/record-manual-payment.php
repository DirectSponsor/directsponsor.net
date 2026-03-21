<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include transaction logger (fail-safe)
if (file_exists(__DIR__ . '/../scripts/transaction-logger.php')) {
    @include_once __DIR__ . '/../scripts/transaction-logger.php';
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

function sendResponse($success, $data = null, $error = null) {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error,
        'timestamp' => date('c')
    ]);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Only POST requests allowed');
}

try {
    // Validate required fields
    $required_fields = ['recipient', 'purpose', 'amount'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            sendResponse(false, null, "Field '$field' is required");
        }
    }

    // Validate file upload
    if (!isset($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        sendResponse(false, null, 'Screenshot upload is required');
    }

    $recipient = trim($_POST['recipient']);
    $purpose = trim($_POST['purpose']);
    $amount = intval($_POST['amount']);
    $admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : '';

    // Validate amount
    if ($amount <= 0) {
        sendResponse(false, null, 'Amount must be greater than 0');
    }

    // Get recipient username based on recipient ID
    $recipient_usernames = [
        'evans' => 'evans'
    ];
    
    if (!isset($recipient_usernames[$recipient])) {
        sendResponse(false, null, 'Invalid recipient selected');
    }
    
    $recipient_username = $recipient_usernames[$recipient];

    // Generate payment ID
    $current_month = date('Y-m');
    $payment_id = 'manual_' . date('Y_m') . '_' . str_pad(getNextPaymentNumber($current_month), 3, '0', STR_PAD_LEFT);

    // Handle file upload
    $uploaded_file = handleFileUpload($_FILES['screenshot'], $recipient_username, $payment_id);

    // Create payment record
    $payment = [
        'payment_id' => $payment_id,
        'recipient' => $recipient,
        'recipient_username' => $recipient_username,
        'recipient_name' => getRecipientDisplayName($recipient),
        'amount_sats' => $amount,
        'purpose' => $purpose,
        'payment_date' => date('c'),
        'admin_recorded_at' => date('c'),
        'payment_method' => 'coinos_manual',
        'screenshot_path' => $uploaded_file,
        'admin_notes' => $admin_notes,
        'status' => 'sent',
        'recipient_confirmation' => [
            'confirmed_at' => null,
            'confirmation_status' => 'pending',
            'recipient_notes' => null
        ]
    ];

    // Save payment
    saveManualPayment($payment);
    
    // Log distribution - this is when "kitty" money becomes actual recipient payment
    if (function_exists('logTransaction')) {
        logTransaction('distribution_from_site_income', [
            'amount_sats' => $amount,
            'recipient_name' => $payment['recipient_name'],
            'payment_method' => 'manual',
            'notes' => $purpose,
            'admin_notes' => $admin_notes,
            'payment_id' => $payment_id,
            'system' => 'manual-distributions',
            'processed_by' => 'admin',
            'source' => 'site_income_kitty',
            'created_at' => date('c')
        ]);
    }

    sendResponse(true, ['payment_id' => $payment_id], null);

} catch (Exception $e) {
    sendResponse(false, null, 'Error: ' . $e->getMessage());
}

function getNextPaymentNumber($month) {
    $data_file = '../data/manual_distributions.json';
    
    if (!file_exists($data_file)) {
        return 1;
    }
    
    $data = json_decode(file_get_contents($data_file), true);
    
    if (!isset($data['distributions'][$month])) {
        return 1;
    }
    
    return count($data['distributions'][$month]['payments']) + 1;
}

function handleFileUpload($file, $recipient_username, $payment_id) {
    $upload_dir = '../uploads/payment_proof/';
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Please upload JPG, PNG, or GIF only.');
    }
    
    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }
    
    // Get file extension
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension']);
    
    // Generate filename: rofl-username-mm-yy.ext
    $filename = sprintf('rofl-%s-%s.%s', 
        $recipient_username, 
        date('m-y'), 
        $extension
    );
    
    $filepath = $upload_dir . $filename;
    
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save screenshot file');
    }
    
    return $filename;
}

function getRecipientDisplayName($recipient) {
    $recipient_names = [
        'evans' => 'Evans - Kenya Reforestation Project'
    ];
    
    return $recipient_names[$recipient] ?? $recipient;
}

function saveManualPayment($payment) {
    $data_file = '../data/manual_distributions.json';
    $month = date('Y-m');
    
    // Load existing data
    $data = [];
    if (file_exists($data_file)) {
        $data = json_decode(file_get_contents($data_file), true) ?: [];
    }
    
    // Initialize structure if needed
    if (!isset($data['distributions'])) {
        $data['distributions'] = [];
    }
    
    if (!isset($data['distributions'][$month])) {
        $data['distributions'][$month] = [
            'payments' => [],
            'total_sent_sats' => 0,
            'distribution_complete' => false
        ];
    }
    
    // Add payment
    $data['distributions'][$month]['payments'][] = $payment;
    $data['distributions'][$month]['total_sent_sats'] += $payment['amount_sats'];
    
    // Update metadata
    $data['last_updated'] = date('c');
    $data['total_payments'] = 0;
    $data['total_amount_sats'] = 0;
    
    foreach ($data['distributions'] as $month_data) {
        $data['total_payments'] += count($month_data['payments']);
        $data['total_amount_sats'] += $month_data['total_sent_sats'];
    }
    
    // Save data
    if (!file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT))) {
        throw new Exception('Failed to save payment data');
    }
}
?>