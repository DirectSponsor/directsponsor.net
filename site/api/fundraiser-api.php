<?php
// DirectSponsor Data Directory Configuration
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('PROJECTS_DIR', DS_DATA_DIR . '/projects');

/**
 * Fundraiser API v2 - Simplified
 * 
 * Directory structure: /projects/username/001.html, /projects/username/002.html, etc.
 * Active project = lowest numbered HTML file in user's directory
 * Completed projects in: /projects/username/completed/
 */

function extractByComments($html, $tag, $default = '') {
    $pattern = '/<!-- ' . preg_quote($tag, '/') . ' -->(.*?)<!-- end ' . preg_quote($tag, '/') . ' -->/s';
    if (preg_match($pattern, $html, $matches)) {
        return trim($matches[1]);
    }
    return $default;
}

function parseProjectFromHTML($htmlFile, $projectId) {
    $html = file_get_contents($htmlFile);
    if ($html === false) {
        return null;
    }
    
    // Extract owner from HTML comment
    $owner = null;
    if (preg_match('/<!-- OWNER: ([^\s]+) -->/', $html, $matches)) {
        $owner = $matches[1];
    }
    
    // Get current amount directly from HTML file (NOT from ledger)
    $currentAmount = (int)str_replace(',', '', extractByComments($html, 'current-amount', '0'));
    
    // Extract data using comment tags
    return [
        'project_id' => $projectId,
        'title' => extractByComments($html, 'title', 'Untitled Project'),
        'description' => extractByComments($html, 'description', ''),
        'recipient_user_id' => extractByComments($html, 'recipient-name', ''),
        'recipient_name' => extractByComments($html, 'recipient-name', ''),
        'target_amount' => (int)str_replace(',', '', extractByComments($html, 'target-amount', '0')),
        'current_amount' => $currentAmount, // Live balance from donations
        'currency' => 'sats',
        'category' => extractByComments($html, 'category', 'General'),
        'status' => extractByComments($html, 'status', 'active'),
        'created_date' => time(),
        'lightning_address' => extractByComments($html, 'lightning-address', ''),
        'website_url' => extractByComments($html, 'website-url', ''),
        'location' => extractByComments($html, 'location', ''),
        'owner' => $owner,
        'filename' => basename($htmlFile)
    ];
}

function convertToFundraiserFormat($projectData) {
    // Build direct URL to project HTML file
    $projectUrl = '/projects/' . ($projectData['owner'] ?? 'unknown') . '/' . $projectData['project_id'] . '.html';
    
    return [
        'id' => $projectData['project_id'],
        'url' => $projectUrl, // Direct link to standalone HTML file
        'title' => $projectData['title'],
        'tagline' => substr($projectData['description'], 0, 100) . '...',
        'description' => $projectData['description'],
        'story' => $projectData['description'],
        'owner_user_id' => $projectData['owner'] ?? 'Anonymous',
        'goal_amount' => $projectData['target_amount'] ?? 0,
        'current_amount' => $projectData['current_amount'] ?? 0,
        'currency' => $projectData['currency'] ?? 'sats',
        'category' => $projectData['category'] ?? 'General',
        'type' => 'one-time',
        'status' => $projectData['status'] ?? 'active',
        'created_date' => $projectData['created_date'] ?? time(),
        'last_updated' => $projectData['created_date'] ?? time(),
        'lightning_address' => $projectData['lightning_address'] ?? null,
        'hero_image' => null,
        'verification' => ['verified' => true, 'verified_by' => 'admin'],
        'contributions' => [],
        'updates' => [],
        'contact_info' => [
            'location' => $projectData['location'] ?? null
        ],
        'filename' => $projectData['filename'] ?? null
    ];
}

/**
 * Get active project for a user (lowest numbered HTML file)
 */
function getActiveProjectForUser($username) {
    $userDir = PROJECTS_DIR . '/' . $username;
    if (!is_dir($userDir)) {
        return null;
    }
    
    $htmlFiles = glob($userDir . '/active/*.html');
    $projectNumbers = [];
    
    foreach ($htmlFiles as $file) {
        if (preg_match('/\/(\d+)\.html$/', $file, $matches)) {
            $projectNumbers[$matches[1]] = $file;
        }
    }
    
    if (empty($projectNumbers)) {
        return null;
    }
    
    ksort($projectNumbers); // Sort by project number
    $lowestNumber = key($projectNumbers);
    return [$lowestNumber, $projectNumbers[$lowestNumber]];
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    // List all active projects (one per user - lowest numbered)
    try {
        $projects = [];
        
        if (is_dir(PROJECTS_DIR)) {
            $userDirs = glob(PROJECTS_DIR . '/*', GLOB_ONLYDIR);
            foreach ($userDirs as $userDir) {
                $username = basename($userDir);
                
                // Skip special directories
                if (in_array($username, ['completed', 'images', 'img'])) continue;
                
                // Get active project (lowest numbered)
                $activeProject = getActiveProjectForUser($username);
                if ($activeProject) {
                    list($projectId, $projectFile) = $activeProject;
                    $projectData = parseProjectFromHTML($projectFile, $projectId);
                    if ($projectData) {
                        $projects[] = convertToFundraiserFormat($projectData);
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'fundraisers' => $projects
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load projects: ' . $e->getMessage()
        ]);
    }
} else if ($action === 'get') {
    // Get specific project by ID (searches all users, or specific user if username given)
    $fundraiserId = $_GET['id'] ?? '';
    $hintUsername = preg_replace('/[^a-z0-9_-]/i', '', $_GET['username'] ?? '');
    
    if (!$fundraiserId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Fundraiser ID required'
        ]);
        exit;
    }
    
    try {
        $projectData = null;
        
        if (is_dir(PROJECTS_DIR)) {
            if ($hintUsername) {
                // Check active/ first, then completed/ for the hinted user only
                foreach (['active', 'completed'] as $subdir) {
                    $projectFile = PROJECTS_DIR . '/' . $hintUsername . '/' . $subdir . '/' . $fundraiserId . '.html';
                    if (file_exists($projectFile)) {
                        $projectData = parseProjectFromHTML($projectFile, $fundraiserId);
                        break;
                    }
                }
            } else {
                // No username hint — search all user active/ directories only
                $userDirs = glob(PROJECTS_DIR . '/*', GLOB_ONLYDIR);
                foreach ($userDirs as $userDir) {
                    $username = basename($userDir);
                    if (in_array($username, ['completed', 'images', 'img'])) continue;
                    $projectFile = $userDir . '/active/' . $fundraiserId . '.html';
                    if (file_exists($projectFile)) {
                        $projectData = parseProjectFromHTML($projectFile, $fundraiserId);
                        if ($projectData) break;
                    }
                }
            }
        }
        
        if (!$projectData) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Fundraiser not found'
            ]);
            exit;
        }
        
        $fundraiser = convertToFundraiserFormat($projectData);
        
        echo json_encode([
            'success' => true,
            'fundraiser' => $fundraiser
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load fundraiser: ' . $e->getMessage()
        ]);
    }
} else if ($action === 'user_projects') {
    // Get all projects (active + completed) for a specific user
    $username = preg_replace('/[^a-z0-9_-]/i', '', $_GET['username'] ?? '');
    if (!$username) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username required']);
        exit;
    }

    $userDir = PROJECTS_DIR . '/' . $username;
    if (!is_dir($userDir)) {
        echo json_encode(['success' => true, 'active' => [], 'completed' => []]);
        exit;
    }

    $active = [];
    foreach (glob($userDir . '/active/*.html') ?: [] as $file) {
        if (preg_match('/\/(\d+)\.html$/', $file, $m)) {
            $p = parseProjectFromHTML($file, $m[1]);
            if ($p) $active[] = convertToFundraiserFormat($p);
        }
    }

    $completed = [];
    foreach (glob($userDir . '/completed/*.html') ?: [] as $file) {
        if (preg_match('/\/(\d+)\.html$/', $file, $m)) {
            $p = parseProjectFromHTML($file, $m[1]);
            if ($p) $completed[] = convertToFundraiserFormat($p);
        }
    }

    echo json_encode(['success' => true, 'active' => $active, 'completed' => $completed]);

} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid action. Supported actions: list, get, user_projects'
    ]);
}
?>
