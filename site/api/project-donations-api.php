<?php
// DirectSponsor Data Directory Configuration
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('PROJECTS_DIR', DS_DATA_DIR . '/projects');
define('DATA_DIR', DS_DATA_DIR . '/data');
define('LOGS_DIR', DS_DATA_DIR . '/logs');

/**
 * PROJECT DONATIONS API - For specific projects only (001, 002, etc.)
 * Uses each project's individual Coinos API key for direct payments
 * Does NOT handle Project 000 (use site-income-api.php instead)
 * 
 * Deployed: 2025-10-11 - Clean unified project donations system
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

define('COINOS_API_URL', 'https://coinos.io/api');

// Include transaction logger (fail-safe)
if (file_exists(__DIR__ . '/../scripts/transaction-logger.php')) {
    @include_once __DIR__ . '/../scripts/transaction-logger.php';
}

function logProjectPayment($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logDir = LOGS_DIR;
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/project_payments.log', 
        "[$timestamp] [$level] $message\n", FILE_APPEND | LOCK_EX);
}

function createProjectInvoice($project_id, $amount, $donor_name = '', $message = '', $donor_username = null) {
    // PROJECT DONATIONS API: Only handle projects 001+, reject site income
    if ($project_id === '000' || $project_id === 'site-income') {
        return ['success' => false, 'error' => 'Site income donations must use site-income-api.php'];
    }
    
    logProjectPayment("DEBUG: Starting invoice creation for project $project_id", 'DEBUG');
    
    // Find project config.json in user directory structure
    $configFile = null;
    $htmlFile = null;
    $foundUsername = null;
    $userDirs = glob(PROJECTS_DIR . '/*', GLOB_ONLYDIR);
    
    logProjectPayment("DEBUG: Found user directories: " . json_encode($userDirs), 'DEBUG');
    
    foreach ($userDirs as $userDir) {
        $testConfig = $userDir . '/' . $project_id . '-config.json';
        $testHtml = $userDir . '/active/' . $project_id . '.html';
        
        logProjectPayment("DEBUG: Testing config: $testConfig", 'DEBUG');
        
        if (file_exists($testConfig) && file_exists($testHtml)) {
            $configFile = $testConfig;
            $htmlFile = $testHtml;
            $foundUsername = basename($userDir);
            logProjectPayment("DEBUG: Found config and HTML files at $configFile (username: $foundUsername)", 'DEBUG');
            break;
        }
    }
    
    if (!$configFile || !$htmlFile) {
        logProjectPayment("DEBUG: Config or HTML file not found", 'ERROR');
        return ['success' => false, 'error' => 'Project not found'];
    }
    
    // Load config (just API key)
    $configContents = file_get_contents($configFile);
    logProjectPayment("DEBUG: Config file contents: $configContents", 'DEBUG');
    
    $config = json_decode($configContents, true);
    if (!$config || !isset($config['recipient_wallet']['api_key'])) {
        logProjectPayment("DEBUG: Config parse failed or API key missing", 'ERROR');
        return ['success' => false, 'error' => 'Project configuration invalid'];
    }
    
    if ($config['recipient_wallet']['type'] !== 'coinos') {
        return ['success' => false, 'error' => 'Project does not use Coinos wallet'];
    }
    
    $recipient_api_key = $config['recipient_wallet']['api_key'];
    $api_key_preview = substr($recipient_api_key, 0, 20) . '...' . substr($recipient_api_key, -20);
    logProjectPayment("DEBUG: Loaded API key from config: $api_key_preview", 'DEBUG');
    
    // Extract project title from HTML
    $html = file_get_contents($htmlFile);
    $projectTitle = 'Project ' . $project_id;
    if (preg_match('/<!-- title -->(.*?)<!-- end title -->/s', $html, $matches)) {
        $projectTitle = trim($matches[1]);
    }
    
    // Create invoice — API key identifies the account, no user wrapper needed
    $invoice_data = [
        'invoice' => [
            'amount' => $amount,
            'type' => 'lightning',
            'memo' => "Donation to: " . $projectTitle . ($message ? " - " . $message : ""),
            'webhook' => 'https://directsponsor.net/webhook.php',
            'secret' => 'directsponsor_webhook_secret_2025'
        ]
    ];
    
    $resolved_username = $config['recipient_wallet']['coinos_username'] ?? $foundUsername;
    logProjectPayment("DEBUG: Invoice username resolved to: $resolved_username (coinos_username in config: " . ($config['recipient_wallet']['coinos_username'] ?? 'NOT SET') . ", foundUsername: $foundUsername)", 'DEBUG');
    logProjectPayment("DEBUG: About to call Coinos API with key preview: " . substr($recipient_api_key, 0, 20) . '...' . substr($recipient_api_key, -20), 'DEBUG');
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => COINOS_API_URL . '/invoice',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($invoice_data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $recipient_api_key
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);
    
    logProjectPayment("DEBUG: Coinos API response (HTTP $http_code): $response", 'DEBUG');
    
    if ($curl_error) {
        logProjectPayment("Coinos API curl error: $curl_error", 'ERROR');
        return ['success' => false, 'error' => 'Payment service unavailable'];
    }
    
    if ($http_code !== 200) {
        logProjectPayment("Coinos API HTTP error: $http_code - $response", 'ERROR');
        return ['success' => false, 'error' => 'Failed to create invoice'];
    }
    
    $invoice = json_decode($response, true);
    if (!$invoice) {
        logProjectPayment("Invalid Coinos API response: $response", 'ERROR');
        return ['success' => false, 'error' => 'Invalid payment service response'];
    }
    
    logProjectPayment("Created project invoice for project $project_id, amount: $amount sats", 'INFO');
    
    $donation_id = uniqid("pdon_");
    
    // NOTE: Invoice creation is NOT logged to transaction ledger
    // Only confirmed payments are logged via webhook processing
    
    // Store pending donation for payment tracking (project system only)
    storePendingProjectDonation($project_id, $donation_id, $amount, $donor_name, $message, $invoice["text"], $invoice["paymentHash"] ?? $invoice["hash"] ?? "", $foundUsername ?? '', $donor_username);
    
    return [
        'success' => true,
        'data' => [
            'donation_id' => $donation_id,
            'invoice' => $invoice['text'], // Lightning invoice
            'payment_hash' => $invoice['paymentHash'] ?? $invoice['hash'] ?? '',
            'amount_sats' => $amount,
            'qr_code' => "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($invoice['text'])
        ]
    ];
}

function getProjectInfo($project_id) {
    // Load project - supports both flat and user directory structures
    $project_files = glob(PROJECTS_DIR . "/" . $project_id . "-*.json");
    if (empty($project_files)) {
        // Try user directory structure: /projects/username/*.json
        $project_files = glob(PROJECTS_DIR . "/*/" . $project_id . "-*.json");
    }
    if (empty($project_files)) {
        return ['success' => false, 'error' => 'Project not found'];
    }
    
    $project = json_decode(file_get_contents($project_files[0]), true);
    
    // Handle progress calculation for projects with/without targets
    $target_amount = $project['target_amount'];
    $current_amount = $project['current_amount'] ?? 0;
    
    if ($target_amount === null || $target_amount === 0) {
        // Projects without specific targets
        $progress_percentage = null;
    } else {
        $progress_percentage = round(($current_amount / $target_amount) * 100, 1);
    }
    
    // Calculate unique supporters (distinct donor names/emails)
    $donations = $project['donations'] ?? [];
    $uniqueSupporters = [];
    foreach ($donations as $donation) {
        $donor_key = $donation['donor_name'] ?? $donation['donor_email'] ?? 'anonymous';
        if (!empty($donor_key)) {
            $uniqueSupporters[$donor_key] = true;
        }
    }
    
    return [
        'success' => true,
        'project_id' => $project['project_id'],
        'title' => $project['title'],
        'description' => $project['description'],
        'target_amount' => $target_amount,
        'current_amount' => $current_amount,
        'progress_percentage' => $progress_percentage,
        'recipient_name' => $project['recipient_name'],
        'status' => $project['status'],
        'donations_count' => count($donations),
        'supporters_count' => count($uniqueSupporters),
        'donors_count' => count($uniqueSupporters), // Legacy field name for frontend
        'is_general_fund' => $project['is_general_fund'] ?? false
    ];
}

function getProjectDonations($project_id, $limit = 10) {
    // Load project to get current donations - supports both flat and user directory structures
    $project_files = glob(PROJECTS_DIR . "/" . $project_id . "-*.json");
    if (empty($project_files)) {
        // Try user directory structure: /projects/username/*.json
        $project_files = glob(PROJECTS_DIR . "/*/" . $project_id . "-*.json");
    }
    if (empty($project_files)) {
        return [];
    }
    
    $project = json_decode(file_get_contents($project_files[0]), true);
    $donations = $project['donations'] ?? [];
    
    // Sort by date (newest first) and limit
    usort($donations, function($a, $b) {
        $dateA = $a['confirmed_at'] ?? $a['created_at'] ?? '1970-01-01';
        $dateB = $b['confirmed_at'] ?? $b['created_at'] ?? '1970-01-01';
        return strtotime($dateB) - strtotime($dateA);
    });
    
    return array_slice($donations, 0, $limit);
}

function checkPaymentStatus($donation_id) {
    // EFFICIENT: Just check if still in pending
    // If NOT in pending = webhook already processed it = PAID
    // If still in pending = waiting for payment
    
    $pending_file = DATA_DIR . '/project-donations-pending/pending.json';
    if (file_exists($pending_file)) {
        $pending = json_decode(file_get_contents($pending_file), true) ?: [];
        foreach ($pending as $donation) {
            if ($donation['donation_id'] === $donation_id) {
                // Still pending
                return [
                    'success' => true,
                    'data' => [
                        'status' => 'pending',
                        'donation_id' => $donation_id,
                        'amount_sats' => $donation['amount_sats']
                    ]
                ];
            }
        }
    }
    
    // Not in pending = webhook removed it = payment confirmed
    return [
        'success' => true,
        'data' => [
            'status' => 'paid',
            'donation_id' => $donation_id
        ]
    ];
}

// Main API handling - COPIED FROM WORKING DONATION API
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        // Handle project donation requests
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing action parameter']);
            exit;
        }
        
        switch ($input['action']) {
            case 'create_invoice':
                if (!isset($input['project_id'], $input['amount'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing project_id or amount']);
                    exit;
                }
                
                $result = createProjectInvoice(
                    $input['project_id'],
                    (int)$input['amount'],
                    $input['donor_name'] ?? '',
                    $input['message'] ?? '',
                    $input['donor_username'] ?? null
                );
                echo json_encode($result);
                break;
                
            case 'get_project':
                if (!isset($input['project_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing project_id']);
                    exit;
                }
                
                $result = getProjectInfo($input['project_id']);
                if ($result['success']) {
                    echo json_encode(['success' => true, 'data' => $result]);
                } else {
                    echo json_encode($result);
                }
                break;
                
            case 'get_donations':
                if (!isset($input['project_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing project_id']);
                    exit;
                }
                
                $donations = getProjectDonations($input['project_id'], $input['limit'] ?? 10);
                echo json_encode(['success' => true, 'data' => $donations]);
                break;
                
            case 'check_payment':
                if (!isset($input['donation_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing donation_id']);
                    exit;
                }
                
                $result = checkPaymentStatus($input['donation_id']);
                echo json_encode($result);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    logProjectPayment("API Exception: " . $e->getMessage(), 'ERROR');
}

// Store pending project donation (project system only - no cross-system pollution)
function storePendingProjectDonation($project_id, $donation_id, $amount, $donor_name, $message, $invoice, $payment_hash, $username = '', $donor_username = null) {
    // Create project-specific pending donation entry
    $donation_entry = [
        'donation_id' => $donation_id,
        'project_id' => $project_id,
        'username' => $username,
        'donor_username' => $donor_username,
        'donor_name' => $donor_name ?: ($donor_username ?: 'Anonymous'),
        'donor_message' => $message,
        'amount_sats' => $amount,
        'invoice' => $invoice,
        'payment_hash' => $payment_hash,
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ];
    
    // Store in project-specific pending file (isolated from site income system)
    $pending_dir = DATA_DIR . '/project-donations-pending';
    if (!is_dir($pending_dir)) {
        mkdir($pending_dir, 0755, true);
    }
    
    $pending_file = $pending_dir . '/pending.json';
    $pending = [];
    if (file_exists($pending_file)) {
        $pending = json_decode(file_get_contents($pending_file), true) ?: [];
    }
    $pending[] = $donation_entry;
    file_put_contents($pending_file, json_encode($pending, JSON_PRETTY_PRINT));
    
    logProjectPayment("Stored pending project donation: $donation_id for project $project_id");
}
?>
