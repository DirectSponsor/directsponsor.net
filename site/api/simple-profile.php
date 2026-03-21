<?php
// DirectSponsor Data Directory Configuration
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('USERDATA_DIR', DS_DATA_DIR . '/userdata');

/**
 * DirectSponsor Simple Profile API
 * 
 * A lightweight profile management system for infrequently updated user data.
 * Designed to complement coins-balance.php with efficient sync strategy:
 * - Balance data = frequent updates, small files, sync often
 * - Profile data = rare updates, larger files, sync rarely
 * 
 * FILE STRUCTURE:
 * {
 *   "user_id": "12345",
 *   "level": 3,
 *   "username": "player123",
 *   "email": "",
 *   "joined_date": 1693334400,
 *   "settings": {
 *     "notifications": true,
 *     "theme": "default"
 *   },
 *   "stats": {
 *     "total_claims": 45,
 *     "total_games_played": 128,
 *     "total_won": 247.50
 *   },
 *   "roles": ["member"],
 *   "fundraiser_permissions": [],
 *   "public_profile": false,
 *   "last_profile_update": 1693334500
 * }
 * 
 * FEATURES:
 * - Simple read/write operations like nano
 * - Focused on infrequently updated data (level, settings, stats)
 * - Matches the simple architecture of balance system
 * - Minimal file I/O for sync efficiency
 * 
 * ENDPOINTS:
 * GET  ?action=profile        - Returns user profile data
 * POST ?action=update_profile - Updates profile fields
 * POST ?action=update_level   - Updates user level specifically
 * POST ?action=update_stats   - Updates user statistics
 * 
 * @author DirectSponsor Dev Team
 * @version 1.0
 * @date 2025-08-31
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get user ID from request (using new sessionStorage system)
 * @return string|null User ID or null if not provided
 */
function getUserId() {
    // Try different auth methods in order of preference
    if (!empty($_GET['user_id'])) {
        return $_GET['user_id'];
    }
    if (!empty($_POST['user_id'])) {
        return $_POST['user_id'];
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['user_id'])) {
        return $input['user_id'];
    }
    return null;
}

/**
 * Find profile file by username (searches all profile files)
 * @param string $username Username to search for
 * @return string|null Path to profile file, or null if not found
 */
function findProfileByUsername($username) {
    $profilesDir = USERDATA_DIR . '/profiles';
    if (!is_dir($profilesDir)) {
        return null;
    }
    
    // Get all profile files
    $files = glob($profilesDir . '/*.txt');
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['username']) && $data['username'] === $username) {
            return $file;
        }
    }
    
    return null;
}

/**
 * Search for profiles matching a partial username
 * @param string $query Partial username to search for
 * @param int $limit Maximum number of results (default 10)
 * @return array Array of matching profiles
 */
function searchProfiles($query, $limit = 10) {
    $profilesDir = USERDATA_DIR . '/profiles';
    if (!is_dir($profilesDir)) {
        return [];
    }
    
    $query = strtolower(trim($query));
    if (empty($query)) {
        return [];
    }
    
    $files = glob($profilesDir . '/*.txt');
    $matches = [];
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['username'])) {
            continue;
        }
        
        $username = strtolower($data['username']);
        $userId = $data['user_id'] ?? '';
        
        // Check if query matches username or user_id (PHP 7.4 compatible)
        if (strpos($username, $query) !== false || strpos(strtolower($userId), $query) !== false) {
            $matches[] = [
                'user_id' => $data['user_id'],
                'username' => $data['username'],
                'display_name' => $data['display_name'] ?? $data['username'],
                'avatar' => $data['avatar'] ?? '👤',
                'roles' => $data['roles'] ?? ['member']
            ];
            
            if (count($matches) >= $limit) {
                break;
            }
        }
    }
    
    return $matches;
}

/**
 * Fetch profile data from auth server sync API
 * @param string $userId User ID
 * @return array|null Profile data from auth server, or null on failure
 */
function fetchProfileFromAuthServer($userId) {
    $authUrl = "https://auth.directsponsor.org/api/sync.php?action=get&user_id=" . urlencode($userId) . "&data_type=profile";
    
    // Use curl for better error handling
    $ch = curl_init($authUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && $data['success'] && isset($data['data'])) {
            return $data['data'];
        }
    }
    
    return null;
}

/**
 * Load user profile data with default structure
 * @param string $profileFile Path to profile file
 * @param string $userId User ID for default data
 * @return array Profile data structure
 */
function loadProfileData($profileFile, $userId) {
    if (file_exists($profileFile)) {
        $data = json_decode(file_get_contents($profileFile), true);
        if ($data && is_array($data)) {
            // Ensure all required fields exist
            return array_merge([
                'user_id' => $userId,
                'level' => 1,
                'username' => '',
                'display_name' => '',
                'avatar' => '👤',
                'email' => '',
                'bio' => '',
                'location' => '',
                'website' => '',
                'joined_date' => time(),
                'settings' => [
                    'notifications' => true,
                    'theme' => 'default'
                ],
                'stats' => [
                    'total_claims' => 0,
                    'total_games_played' => 0,
                    'total_won' => 0
                ],
                'roles' => ['member'],
                'fundraiser_permissions' => [],
                'public_profile' => false,
                'last_profile_update' => time()
            ], $data);
        }
    }
    
    // LAZY LOADING: File doesn't exist locally, try fetching from auth server
    $authProfile = fetchProfileFromAuthServer($userId);
    if ($authProfile) {
        // Merge auth server data with default structure
        $data = array_merge([
            'user_id' => $userId,
            'level' => 1,
            'username' => $authProfile['username'] ?? '',
            'display_name' => $authProfile['display_name'] ?? $authProfile['username'] ?? '',
            'avatar' => $authProfile['avatar'] ?? '👤',
            'email' => '',
            'bio' => $authProfile['bio'] ?? '',
            'location' => $authProfile['location'] ?? '',
            'website' => $authProfile['website'] ?? '',
            'joined_date' => time(),
            'settings' => [
                'notifications' => true,
                'theme' => 'default'
            ],
            'stats' => [
                'total_claims' => 0,
                'total_games_played' => 0,
                'total_won' => 0
            ],
            'roles' => ['member'],
            'fundraiser_permissions' => [],
            'public_profile' => false,
            'last_profile_update' => time()
        ]);
        
        // Cache the profile locally for future requests
        saveProfileData($profileFile, $data);
        
        return $data;
    }
    
    // Default structure for new users
    return [
        'user_id' => $userId,
        'level' => 1,
        'username' => '',
        'display_name' => '',
        'avatar' => '👤',
        'email' => '',
        'bio' => '',
        'location' => '',
        'website' => '',
        'joined_date' => time(),
        'settings' => [
            'notifications' => true,
            'theme' => 'default'
        ],
        'stats' => [
            'total_claims' => 0,
            'total_games_played' => 0,
            'total_won' => 0
        ],
        'roles' => ['member'],
        'fundraiser_permissions' => [],
        'public_profile' => false,
        'last_profile_update' => time()
    ];
}

/**
 * Save profile data to file
 * @param string $profileFile Path to profile file
 * @param array $data Profile data to save
 * @return bool Success status
 */
function saveProfileData($profileFile, $data) {
    $data['last_profile_update'] = time();
    
    // Ensure directory exists
    $dir = dirname($profileFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    return file_put_contents($profileFile, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

// Main API Logic
$action = $_GET['action'] ?? '';

// Handle search action separately (it has its own auth check)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search') {
    // SEARCH USERS - Find users by partial username (admin only)
    $requesterId = getUserId();
    if (!$requesterId) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    
    // Determine requester's profile file (PHP 7.4 compatible)
    if (strpos($requesterId, '-') === false) {
        $foundFile = findProfileByUsername($requesterId);
        if ($foundFile) {
            $requesterFile = $foundFile;
        } else {
            $requesterFile = USERDATA_DIR . "/profiles/{$requesterId}.txt";
        }
    } else {
        $requesterFile = USERDATA_DIR . "/profiles/{$requesterId}.txt";
    }
    
    $requesterData = loadProfileData($requesterFile, $requesterId);
    
    if (!in_array('admin', $requesterData['roles'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $query = $_GET['query'] ?? '';
    $limit = intval($_GET['limit'] ?? 10);
    
    $results = searchProfiles($query, $limit);
    
    echo json_encode([
        'success' => true,
        'query' => $query,
        'results' => $results,
        'count' => count($results)
    ]);
    exit;
}

// For all other actions, require authentication
$userId = getUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Determine profile file path
// If userId doesn't contain a dash, try to find by username (PHP 7.4 compatible)
if (strpos($userId, '-') === false) {
    $foundFile = findProfileByUsername($userId);
    if ($foundFile) {
        $profileFile = $foundFile;
        // Extract the actual combined user_id from the found profile
        $data = json_decode(file_get_contents($foundFile), true);
        $userId = $data['user_id'] ?? $userId;
    } else {
        $profileFile = USERDATA_DIR . "/profiles/{$userId}.txt";
    }
} else {
    $profileFile = USERDATA_DIR . "/profiles/{$userId}.txt";
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'profile') {
    // GET PROFILE - Return user profile data (with lazy loading from auth server)
    $data = loadProfileData($profileFile, $userId);
    
    echo json_encode([
        'success' => true,
        'profile' => $data
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_profile') {
    // UPDATE PROFILE - Update multiple profile fields
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Load current data
    $data = loadProfileData($profileFile, $userId);
    
    // Update fields (only allow safe fields to be updated) - roles excluded for security
    $allowedFields = ['username', 'display_name', 'avatar', 'email', 'bio', 'location', 'website', 'settings', 'public_profile'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'settings' && is_array($input[$field])) {
                // Merge settings instead of replacing
                $data[$field] = array_merge($data[$field], $input[$field]);
            } else {
                $data[$field] = $input[$field];
            }
        }
    }
    
    // Save updated data
    if (saveProfileData($profileFile, $data)) {
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'profile' => $data
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save profile data']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_level') {
    // UPDATE LEVEL - Specifically for level changes (used by levels system)
    $input = json_decode(file_get_contents('php://input'), true);
    $newLevel = intval($input['level'] ?? 0);
    
    if ($newLevel < 1 || $newLevel > 12) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid level (must be 1-12)']);
        exit;
    }
    
    // Load current data
    $data = loadProfileData($profileFile, $userId);
    
    // Update level
    $data['level'] = $newLevel;
    
    // Save updated data
    if (saveProfileData($profileFile, $data)) {
        echo json_encode([
            'success' => true,
            'new_level' => $newLevel,
            'message' => 'Level updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save level data']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_stats') {
    // UPDATE STATS - Increment user statistics
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Load current data
    $data = loadProfileData($profileFile, $userId);
    
    // Update stats (only allow incrementing)
    $allowedStats = ['total_claims', 'total_games_played', 'total_won'];
    foreach ($allowedStats as $stat) {
        if (isset($input[$stat])) {
            $increment = floatval($input[$stat]);
            if ($increment > 0) {
                $data['stats'][$stat] = ($data['stats'][$stat] ?? 0) + $increment;
            }
        }
    }
    
    // Save updated data
    if (saveProfileData($profileFile, $data)) {
        echo json_encode([
            'success' => true,
            'stats' => $data['stats'],
            'message' => 'Stats updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save stats data']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'manage_roles') {
    // MANAGE ROLES - Admin only function to add/remove roles
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Load current admin's profile to check permissions
    $adminProfileFile = USERDATA_DIR . "/profiles/{$userId}.txt";
    $adminProfile = loadProfileData($adminProfileFile, $userId);
    
    // Check if current user is admin
    if (!in_array('admin', $adminProfile['roles'] ?? [])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    
    $targetUserId = $input['target_user_id'] ?? '';
    $operation = $input['operation'] ?? ''; // 'add' or 'remove'
    $role = $input['role'] ?? '';
    
    if (!$targetUserId || !in_array($operation, ['add', 'remove']) || !$role) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters - need target_user_id, operation (add/remove), and role']);
        exit;
    }
    
    // Load target user's profile
    $targetProfileFile = USERDATA_DIR . "/profiles/{$targetUserId}.txt";
    $targetData = loadProfileData($targetProfileFile, $targetUserId);
    
    // Perform role operation
    $roles = $targetData['roles'] ?? ['member'];
    
    if ($operation === 'add' && !in_array($role, $roles)) {
        $roles[] = $role;
    } elseif ($operation === 'remove' && in_array($role, $roles)) {
        $roles = array_values(array_filter($roles, function($r) use ($role) { return $r !== $role; }));
        // Ensure at least 'member' role remains
        if (empty($roles)) {
            $roles = ['member'];
        }
    }
    
    $targetData['roles'] = $roles;
    
    // Save updated data
    if (saveProfileData($targetProfileFile, $targetData)) {
        echo json_encode([
            'success' => true,
            'target_user' => $targetUserId,
            'new_roles' => $roles,
            'message' => "Role {$operation} successful"
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save role changes']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'check_roles') {
    // CHECK ROLES - Check if user has specific roles
    $requiredRoles = explode(',', $_GET['roles'] ?? '');
    
    $data = loadProfileData($profileFile, $userId);
    $userRoles = $data['roles'] ?? ['member'];
    
    $hasRoles = [];
    foreach ($requiredRoles as $role) {
        $hasRoles[trim($role)] = in_array(trim($role), $userRoles);
    }
    
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'user_roles' => $userRoles,
        'role_checks' => $hasRoles
    ]);
    
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request - Use GET ?action=profile|check_roles|search or POST ?action=update_profile|update_level|update_stats|manage_roles']);
}
?>
