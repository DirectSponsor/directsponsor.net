<?php
// DirectSponsor Data Directory Configuration  
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('PROJECTS_DIR', DS_DATA_DIR . '/projects');
define('SITE_INCOME_DIR', DS_DATA_DIR . '/payments/data/site-income');
define('PROJECT_DONATIONS_DIR', DS_DATA_DIR . '/data/project-donations-pending');
define('LOGS_DIR', DS_DATA_DIR . '/logs');
define('DATA_DIR', DS_DATA_DIR . '/data');
define('LOG_FILE', LOGS_DIR . '/webhook.log');

/**
 * Smart Dual-System Webhook Processor
 * Handles both site income and project donation confirmations
 * Updated: 2025-10-12 - Smart routing between systems + transaction logging
 */

// Simple accounts logging - no complex transaction logger needed

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Configuration
define('WEBHOOK_SECRET', getenv('COINOS_WEBHOOK_SECRET') ?: 'your-webhook-secret-here');

/**
 * Log webhook events
 */
function logWebhook($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Verify webhook signature
 */
function verifyWebhookSignature($payload, $signature) {
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
    return hash_equals($expectedSignature, $signature);
}

/**
 * Load JSON data from file with locking
 */
function loadJsonData($filepath) {
    if (!file_exists($filepath)) {
        return [];
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle || !flock($handle, LOCK_SH)) {
        return [];
    }
    
    $content = fread($handle, filesize($filepath) ?: 0);
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return json_decode($content, true) ?: [];
}

/**
 * Save JSON data to file with locking
 */
function saveJsonData($filepath, $data) {
    $tempFile = $filepath . '.tmp';
    
    $handle = fopen($tempFile, 'w');
    if (!$handle || !flock($handle, LOCK_EX)) {
        return false;
    }
    
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
    flock($handle, LOCK_UN);
    fclose($handle);
    
    return rename($tempFile, $filepath);
}

/**
 * Extract payment hash from BOLT11 invoice
 */
function extractPaymentHash($bolt11) {
    // This is a simplified extraction - in production, use a proper BOLT11 decoder
    // For now, we'll use the invoice string itself as the identifier
    return $bolt11;
}

/**
 * Process payment confirmation - handles both site income and project donations
 */
function processPaymentConfirmation($webhookData) {
    logWebhook("Processing payment confirmation for invoice: " . ($webhookData['hash'] ?? 'unknown'));
    
    // Try PROJECT DONATIONS first
    $projectPending = loadJsonData(PROJECT_DONATIONS_DIR . '/pending.json');
    $matchedDonation = null;
    $foundIndex = -1;
    $isProjectDonation = false;
    
    foreach ($projectPending as $index => $donation) {
        if (
            (isset($webhookData['hash']) && $donation['payment_hash'] === $webhookData['hash']) ||
            (isset($webhookData['hash']) && $donation['invoice'] === $webhookData['hash']) ||
            (isset($webhookData['id']) && $donation['coinos_id'] === $webhookData['id'])
        ) {
            $foundIndex = $index;
            $matchedDonation = $donation;
            $isProjectDonation = true;
            logWebhook("Found PROJECT DONATION match for project: " . $donation['project_id']);
            break;
        }
    }
    
    // If not found in project donations, try SITE INCOME donations
    if ($matchedDonation === null) {
        $sitePending = loadJsonData(SITE_INCOME_DIR . '/pending.json');
        foreach ($sitePending as $index => $donation) {
            if (
                (isset($webhookData['hash']) && $donation['payment_hash'] === $webhookData['hash']) ||
                (isset($webhookData['hash']) && $donation['invoice'] === $webhookData['hash']) ||
                (isset($webhookData['id']) && $donation['coinos_id'] === $webhookData['id'])
            ) {
                $foundIndex = $index;
                $matchedDonation = $donation;
                $isProjectDonation = false;
                logWebhook("Found SITE INCOME donation match");
                break;
            }
        }
    }
    
    if ($matchedDonation === null) {
        logWebhook("No matching pending donation found in either system", 'WARNING');
        return false;
    }
    
    // Process based on donation type
    if ($isProjectDonation) {
        return processProjectDonation($matchedDonation, $foundIndex, $webhookData);
    } else {
        return processSiteIncomeDonation($matchedDonation, $foundIndex, $webhookData);
    }
}

/**
 * Find project HTML file in user directory structure
 */
function findProjectHtmlFile($projectId, $username = null) {
    // If username provided, check that user's directory first
    if ($username) {
        $userDir = PROJECTS_DIR . '/' . $username;
        $htmlFile = $userDir . '/active/' . $projectId . '.html';
        if (file_exists($htmlFile)) {
            return ['file' => $htmlFile, 'completed' => false, 'username' => $username];
        }
    }
    
    // Fall back: search all user directories
    $userDirs = glob(PROJECTS_DIR . '/*', GLOB_ONLYDIR);
    foreach ($userDirs as $userDir) {
        $basename = basename($userDir);
        // Skip completed and img directories
        if ($basename === 'completed' || $basename === 'img') {
            continue;
        }
        // Skip already-checked username
        if ($username && $basename === $username) {
            continue;
        }
        
        $htmlFile = $userDir . '/active/' . $projectId . '.html';
        if (file_exists($htmlFile)) {
            return ['file' => $htmlFile, 'completed' => false, 'username' => $basename];
        }
    }
    
    // Not found in active projects - check if it's in completed
    $completedDirs = glob(PROJECTS_DIR . '/*/completed', GLOB_ONLYDIR);
    foreach ($completedDirs as $completedDir) {
        $htmlFile = $completedDir . '/' . $projectId . '.html';
        if (file_exists($htmlFile)) {
            logWebhook("Project $projectId already completed - donation arrived late");
            return ['file' => $htmlFile, 'completed' => true];
        }
    }
    
    return null;
}

/**
 * Find next available active project
 */
function findNextActiveProject() {
    $userDirs = glob(PROJECTS_DIR . '/*', GLOB_ONLYDIR);
    $allProjects = [];
    
    foreach ($userDirs as $userDir) {
        $basename = basename($userDir);
        if ($basename === 'completed' || $basename === 'img') {
            continue;
        }
        
        $htmlFiles = glob($userDir . '/active/*.html');
        foreach ($htmlFiles as $htmlFile) {
            $filename = basename($htmlFile, '.html');
            if (preg_match('/^(\d+)$/', $filename, $matches)) {
                $allProjects[] = ['id' => $matches[1], 'file' => $htmlFile];
            }
        }
    }
    
    if (empty($allProjects)) {
        return null;
    }
    
    // Sort by project ID (lowest first)
    usort($allProjects, function($a, $b) {
        return intval($a['id']) - intval($b['id']);
    });
    
    return $allProjects[0];
}

/**
 * Update HTML file with new donation using file locking
 */
function updateProjectHtml($htmlFile, $donation, $amountSats) {
    $lockFile = $htmlFile . '.lock';
    $fp = fopen($lockFile, 'w');
    if (!$fp || !flock($fp, LOCK_EX)) {
        logWebhook("Failed to acquire lock for $htmlFile", 'ERROR');
        return false;
    }
    
    try {
        $html = file_get_contents($htmlFile);
        if ($html === false) {
            logWebhook("Failed to read HTML file: $htmlFile", 'ERROR');
            return false;
        }
        logWebhook("Read HTML file OK, length: " . strlen($html));
        
        // Update current amount (match HTML template format with hyphens)
        if (preg_match('/<!-- current-amount -->([^<]+)<!-- end current-amount -->/', $html, $matches)) {
            // Remove commas and parse current amount
            $currentAmount = intval(str_replace(',', '', trim($matches[1])));
            $newAmount = $currentAmount + $amountSats;
            // Format with commas for readability
            $html = preg_replace(
                '/<!-- current-amount -->[^<]+<!-- end current-amount -->/',
                "<!-- current-amount -->" . number_format($newAmount) . "<!-- end current-amount -->",
                $html
            );
        }
        
        // Add to recent donations list (keep last 10)
        $donationHtml = sprintf(
            "            <li>\n                <strong>%s</strong> donated <strong>%d sats</strong>\n                <span class=\"donation-time\">%s</span>\n            </li>\n",
            htmlspecialchars($donation['donor_name']),
            $amountSats,
            date('M j, Y')
        );
        
        if (preg_match('/<!-- recent_donations -->(.*?)<!-- end recent_donations -->/s', $html, $matches)) {
            $existingDonations = $matches[1];
            // Add new donation at the top
            $newDonations = $donationHtml . $existingDonations;
            
            $newDonations = trim($newDonations) . "\n        ";
            
            $html = preg_replace(
                '/<!-- recent_donations -->.*?<!-- end recent_donations -->/s',
                "<!-- recent_donations -->\n        " . $newDonations . "<!-- end recent_donations -->",
                $html
            );
        }
        
        // Write atomically
        $tempFile = $htmlFile . '.tmp';
        $written = file_put_contents($tempFile, $html);
        if ($written === false) {
            logWebhook("Failed to write temp file: $tempFile", 'ERROR');
            return false;
        }
        logWebhook("Wrote temp file OK ($written bytes)");
        
        if (!rename($tempFile, $htmlFile)) {
            logWebhook("Failed to rename $tempFile to $htmlFile", 'ERROR');
            @unlink($tempFile);
            return false;
        }
        logWebhook("Renamed temp file to $htmlFile OK");
        
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);
    }
}

/**
 * Process project donation confirmation
 */
function processProjectDonation($donation, $foundIndex, $webhookData) {
    logWebhook("Processing PROJECT donation for project: " . $donation['project_id']);
    
    // Find project HTML file - use username from pending donation if available
    $projectInfo = findProjectHtmlFile($donation['project_id'], $donation['username'] ?? null);
    
    if (!$projectInfo) {
        logWebhook("Project file not found for project: " . $donation['project_id'], 'ERROR');
        return false;
    }
    
    // If project already completed (race condition — two donors crossed the goal simultaneously),
    // just update the completed file's balance directly. No redirect.
    if ($projectInfo['completed']) {
        logWebhook("Project {$donation['project_id']} already completed — updating completed file balance");
    }
    
    $amountSats = intval($donation['amount_sats']);
    
    // Update HTML file with donation
    if (!updateProjectHtml($projectInfo['file'], $donation, $amountSats)) {
        logWebhook("Failed to update project HTML file", 'ERROR');
        return false;
    }

    // --- Goal-reached check: auto-advance queue ---
    $htmlContent = file_get_contents($projectInfo['file']);
    $currentAmount = 0;
    $targetAmount  = 0;
    if (preg_match('/<!-- current-amount -->([^<]+)<!-- end current-amount -->/', $htmlContent, $m)) {
        $currentAmount = intval(str_replace(',', '', trim($m[1])));
    }
    if (preg_match('/<!-- target-amount -->([^<]+)<!-- end target-amount -->/', $htmlContent, $m)) {
        $targetAmount = intval(str_replace(',', '', trim($m[1])));
    }

    if ($targetAmount > 0 && $currentAmount >= $targetAmount) {
        $username    = $projectInfo['username'];
        $completedId = $donation['project_id'];
        logWebhook("Goal reached for project $completedId (user: $username). current=$currentAmount target=$targetAmount");

        // Move to completed/
        $completedDir = PROJECTS_DIR . '/' . $username . '/completed';
        if (!is_dir($completedDir)) mkdir($completedDir, 0755, true);
        $completedDest = $completedDir . '/' . $completedId . '.html';
        if (rename($projectInfo['file'], $completedDest)) {
            logWebhook("Moved $completedId.html to completed/ for $username");
        } else {
            logWebhook("Failed to move $completedId.html to completed/", 'ERROR');
        }

        // Find next queued project for this recipient (lowest numbered remaining)
        $nextFiles = glob(PROJECTS_DIR . '/' . $username . '/active/*.html') ?: [];
        $nextProjectFile = null;
        $lowestNum = PHP_INT_MAX;
        foreach ($nextFiles as $f) {
            if (preg_match('/\/(\d+)\.html$/', $f, $nm)) {
                if (intval($nm[1]) < $lowestNum) {
                    $lowestNum = intval($nm[1]);
                    $nextProjectFile = $f;
                }
            }
        }

        // Log overpayment (not carried over — shown on completed project page instead)
        $overpayment = $currentAmount - $targetAmount;
        if ($overpayment > 0) {
            logWebhook("Overpayment of $overpayment sats on project $completedId — shown on project page");
        }
        if ($nextProjectFile) {
            logWebhook("Next project now active: " . basename($nextProjectFile));
        } else {
            logWebhook("No next project queued for $username");
        }
    }
    // --- End goal-reached check ---

    // Remove from pending
    $projectPending = loadJsonData(PROJECT_DONATIONS_DIR . '/pending.json');
    array_splice($projectPending, $foundIndex, 1);
    saveJsonData(PROJECT_DONATIONS_DIR . '/pending.json', $projectPending);
    
    // Log to transaction ledger (append-only record)
    $ledgerEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => 'project_donation',
        'project_id' => $donation['project_id'],
        'donation_id' => $donation['donation_id'],
        'donor_username' => $donation['username'] ?? null,
        'donor_name' => $donation['donor_name'],
        'recipient_username' => $projectInfo['username'] ?? null,
        'amount_sats' => $amountSats,
        'payment_hash' => $donation['payment_hash']
    ];
    
    $ledgerFile = DATA_DIR . '/transaction-ledger.json';
    $ledger = loadJsonData($ledgerFile);
    if (!isset($ledger['transactions'])) {
        $ledger['transactions'] = [];
    }
    $ledger['transactions'][] = $ledgerEntry;
    saveJsonData($ledgerFile, $ledger);

    // Also write directly to donor's profile so profile page needs no ledger scan
    $donorUsername = $donation['donor_username'] ?? null;
    if ($donorUsername) {
        $profileGlob = glob(DS_DATA_DIR . '/profiles/' . $donorUsername . '-*.txt');
        if ($profileGlob) {
            $profileData = json_decode(file_get_contents($profileGlob[0]), true) ?: [];
            if (!isset($profileData['donations_made'])) $profileData['donations_made'] = [];
            $profileData['donations_made'][] = [
                'timestamp'   => date('Y-m-d H:i:s'),
                'project_id'  => $donation['project_id'],
                'recipient'   => $projectInfo['username'] ?? null,
                'amount_sats' => $amountSats,
                'donor_name'  => $donation['donor_name'],
            ];
            file_put_contents($profileGlob[0], json_encode($profileData, JSON_PRETTY_PRINT));
            logWebhook("Appended donation to donor profile: $donorUsername");
        }
    }

    logWebhook("Project donation confirmed: {$amountSats} sats to project {$donation['project_id']}");
    return true;
}

/**
 * Process site income donation confirmation (legacy system)
 */
function processSiteIncomeDonation($matchedDonation, $foundIndex, $webhookData) {
    logWebhook("Processing SITE INCOME donation");
    
    // Load site income data
    $pending = loadJsonData(SITE_INCOME_DIR . '/pending.json');
    $donations = loadJsonData(SITE_INCOME_DIR . '/site-income.json');
    $totals = loadJsonData(SITE_INCOME_DIR . '/totals.json');
    
    // Move from pending to confirmed donations
    $confirmedDonation = $matchedDonation;
    $confirmedDonation['confirmed_at'] = date('Y-m-d H:i:s');
    $confirmedDonation['webhook_data'] = $webhookData;
    
    // Check if this is a system distribution
    if (strtolower(trim($confirmedDonation['donor_name'])) === 'system') {
        $confirmedDonation['is_system_distribution'] = true;
        $confirmedDonation['distribution_type'] = 'operating_account';
        logWebhook("Marked as system distribution: {$confirmedDonation['amount_sats']} sats");
    }
    
    // Add to donations array
    $donations[] = $confirmedDonation;
    
    // Remove from pending array
    array_splice($pending, $foundIndex, 1);
    
    // Update totals
    $totals['total_donations']++;
    $totals['total_amount_sats'] += intval($confirmedDonation['amount_sats']);
    $totals['last_updated'] = date('Y-m-d H:i:s');
    
    // Update monthly totals
    $month = date('Y-m', strtotime($confirmedDonation['confirmed_at']));
    if (!isset($totals['monthly_totals'][$month])) {
        $totals['monthly_totals'][$month] = [
            'count' => 0,
            'amount_sats' => 0
        ];
    }
    $totals['monthly_totals'][$month]['count']++;
    $totals['monthly_totals'][$month]['amount_sats'] += intval($confirmedDonation['amount_sats']);
    
    // Save all data
    $success = true;
    $success &= saveJsonData(SITE_INCOME_DIR . '/pending.json', $pending);
    $success &= saveJsonData(SITE_INCOME_DIR . '/site-income.json', $donations);
    $success &= saveJsonData(SITE_INCOME_DIR . '/totals.json', $totals);
    
    if ($success) {
        // Site income confirmations not logged - "kitty" money until distributed
        
        logWebhook("Site income donation confirmed: " . $confirmedDonation['donor_name']);
        return true;
    } else {
        logWebhook("Failed to save site income donation data", 'ERROR');
        return false;
    }
}

// Main webhook processing
try {
    // Get raw POST data
    $rawPayload = file_get_contents('php://input');
    logWebhook("Webhook received, payload length: " . strlen($rawPayload));
    
    // Verify signature if provided
    $signature = $_SERVER['HTTP_X_COINOS_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;
    if ($signature && !verifyWebhookSignature($rawPayload, $signature)) {
        logWebhook("Invalid webhook signature", 'ERROR');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    // Parse JSON payload
    $webhookData = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logWebhook("Invalid JSON payload: " . json_last_error_msg(), 'ERROR');
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    logWebhook("Webhook data parsed: " . json_encode($webhookData));
    
    // Process payment confirmation
    if (processPaymentConfirmation($webhookData)) {
        logWebhook("Webhook processed successfully");
        echo json_encode(['success' => true, 'message' => 'Payment confirmed']);
    } else {
        logWebhook("Webhook processing failed", 'WARNING');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Processing failed']);
    }
    
} catch (Exception $e) {
    logWebhook("Webhook error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Write confirmed donation directly to accounts file (no scanning needed!)
 */
function writeToAccounts($donation, $project, $confirmedDonation) {
    try {
        // Create accounts entry directly from webhook data
        $accountEntry = [
            'id' => 'TX-' . date('Y') . '-' . uniqid(),
            'timestamp' => $confirmedDonation['confirmed_at'],
            'type' => 'project_donation',
            'amount_sats' => intval($donation['amount_sats']),
            'project_id' => $project['project_id'],
            'donor_name' => $donation['donor_name'] ?? 'Anonymous',
            'donor_message' => $donation['donor_message'] ?? '',
            'recipient_name' => $project['recipient_name'],
            'payment_method' => 'lightning',
            'invoice_id' => $donation['donation_id'] ?? null,
            'notes' => 'Direct to ' . $project['title'] . ' project',
            'system' => 'project-donations',
            'processed_by' => 'system',
            'confirmed_at' => $confirmedDonation['confirmed_at'],
            'created_at' => $donation['created_at'] ?? $confirmedDonation['confirmed_at']
        ];
        
        // Append to simple accounts ledger file
        $accountsFile = DATA_DIR . "/accounts-ledger.json";
        
        // Load existing accounts
        $accounts = [];
        if (file_exists($accountsFile)) {
            $content = @file_get_contents($accountsFile);
            if ($content !== false) {
                $accounts = json_decode($content, true) ?: [];
            }
        }
        
        // Add new entry
        $accounts[] = $accountEntry;
        
        // Keep last 10000 entries (performance management)
        if (count($accounts) > 10000) {
            $accounts = array_slice($accounts, -10000);
        }
        
        // Save atomically
        $tempFile = $accountsFile . '.tmp';
        if (file_put_contents($tempFile, json_encode($accounts, JSON_PRETTY_PRINT), LOCK_EX)) {
            rename($tempFile, $accountsFile);
            logWebhook("Added to accounts: {$donation['amount_sats']} sats to {$project['project_id']}");
        } else {
            logWebhook("Failed to write to accounts file", 'WARNING');
        }
        
    } catch (Exception $e) {
        logWebhook("Accounts write error: " . $e->getMessage(), 'WARNING');
        // Never fail webhook for this
    }
}
