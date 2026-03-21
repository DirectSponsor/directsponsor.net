<?php
// DirectSponsor Data Directory Configuration
// Updated for separated architecture
define('DS_DATA_DIR', '/var/www/directsponsor.net/userdata');
define('USERDATA_DIR', DS_DATA_DIR . '/userdata');
define('PROJECTS_DIR', DS_DATA_DIR . '/projects');
define('PAYMENTS_DIR', DS_DATA_DIR . '/payments');
define('ANALYTICS_DIR', DS_DATA_DIR . '/analytics');
define('LOGS_DIR', DS_DATA_DIR . '/logs');

/**
 * Session Bridge - JWT to PHP Sessions with Delta Sync
 * Pure Sync Hybrid Architecture - Phase 2
 * 
 * This bridges JWT authentication to local PHP sessions for lightning-fast user interactions
 */

// Configuration
define('AUTH_SERVER', 'http://auth.directsponsor.org');
define('DATA_SERVER', 'http://data.directsponsor.org'); 
define('JWT_SECRET', 'hybrid_fresh_2025_secret_key');
define('SITE_ID', 'directsponsor');

class SessionBridge {
    
    public function __construct() {
        session_start();
        $this->initializeSession();
    }
    
    /**
     * Main entry point - validates JWT and loads user into session
     */
    public function authenticateUser($jwt = null) {
        // Try to get JWT from various sources
        $jwt = $jwt ?: $this->getJWTFromRequest();
        
        if (!$jwt) {
            return $this->handleGuestUser();
        }
        
        // Decode and validate JWT
        $payload = $this->validateJWT($jwt);
        if (!$payload) {
            return $this->handleInvalidToken();
        }
        
        // Load user into session (with sync)
        return $this->loadUserIntoSession($payload);
    }
    
    /**
     * Get JWT from various request sources
     */
    private function getJWTFromRequest() {
        // From Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // From cookie
        if (isset($_COOKIE['jwt_token'])) {
            return $_COOKIE['jwt_token'];
        }
        
        // From POST/GET parameter
        return $_POST['jwt'] ?? $_GET['jwt'] ?? null;
    }
    
    /**
     * Simple JWT validation (matches our auth server)
     */
    private function validateJWT($jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        
        // Verify signature
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);
        $expectedSignature = $this->base64UrlEncode($signature);
        
        if ($signatureEncoded !== $expectedSignature) {
            return false;
        }
        
        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Load user data into PHP session with sync check and role caching
     */
    private function loadUserIntoSession($jwtPayload) {
        $userId = $jwtPayload['user_id'];
        $username = $jwtPayload['username'];
        
        // Check if we need to sync data from server
        $needsSync = $this->needsDataSync($userId);
        
        if ($needsSync) {
            $this->syncUserDataFromServer($userId);
        }
        
        // Load user profile to get roles
        $userProfile = $this->loadUserProfile($userId, $username);
        
        // Load user into session
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $jwtPayload['email'];
        $_SESSION['jwt_expires'] = $jwtPayload['exp'];
        $_SESSION['user_roles'] = $userProfile['roles'] ?? ['member'];
        
        // CRITICAL: Cache roles in localStorage for instant client-side access
        $this->cacheRolesForClient($_SESSION['user_roles']);
        
        // Initialize user data if not exists
        if (!isset($_SESSION['user_data_loaded'])) {
            $this->initializeUserData($userId);
        }
        
        return true;
    }
    
    /**
     * Check if we need to sync data from server
     */
    private function needsDataSync($userId) {
        // No data loaded yet
        if (!isset($_SESSION['user_data_loaded'])) {
            return true;
        }
        
        // Data is older than 5 minutes
        $lastSync = $_SESSION['last_sync_time'] ?? 0;
        if (time() - $lastSync > 300) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sync user data from data server (delta only)
     */
    private function syncUserDataFromServer($userId) {
        $lastSyncTime = $_SESSION['last_sync_time'] ?? 0;
        
        try {
            // TODO: Implement actual data server call
            // For now, initialize with defaults
            $this->initializeUserData($userId);
            $_SESSION['last_sync_time'] = time();
            
        } catch (Exception $e) {
            // Graceful degradation - work with local data
            error_log("Sync failed: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize user data in session AND create files if they don't exist
     */
    private function initializeUserData($userId) {
        // User data (all local, all instant)
        $_SESSION['balance'] = $_SESSION['balance'] ?? 0.0;
        $_SESSION['last_claim'] = $_SESSION['last_claim'] ?? 0;
        $_SESSION['avatar'] = $_SESSION['avatar'] ?? null;
        $_SESSION['preferences'] = $_SESSION['preferences'] ?? [];
        
        // Sync tracking
        $_SESSION['dirty_fields'] = $_SESSION['dirty_fields'] ?? [];
        $_SESSION['user_data_loaded'] = true;
        $_SESSION['last_sync_time'] = time();
        
        // CRITICAL: Create user files if they don't exist
        $this->ensureUserFilesExist($userId);
        
        error_log("Initialized user data for user_id: $userId");
    }
    
    /**
     * Ensure both balance and profile files exist for the user
     * This fixes the issue where users lose their level/profile data
     */
    private function ensureUserFilesExist($userId) {
        $username = $_SESSION['username'] ?? '';
        $email = $_SESSION['email'] ?? '';
        
        // Ensure balance file exists
        $this->ensureBalanceFileExists($userId);
        
        // Ensure profile file exists with JWT data
        $this->ensureProfileFileExists($userId, $username, $email);
    }
    
    /**
     * DISABLED: Check balance file exists (DO NOT CREATE - prevents data loss)
     * For new account creation, use the coins-balance.php API instead
     */
    private function ensureBalanceFileExists($userId) {
        $balanceFile = USERDATA_DIR . "/balances/{$userId}.txt";
        
        if (!file_exists($balanceFile)) {
            // CRITICAL: Do not create balance files here - this caused data loss!
            // New account balance files should be created through proper API calls
            error_log("WARNING: Balance file missing for user_id: $userId - file: $balanceFile");
            error_log("INFO: For new accounts, balance files should be created via coins-balance.php API");
            
            // Check if this might be a path issue (balance file in different location)
            $alternativePath = dirname(__DIR__) . "/userdata/balances/{$userId}.txt"; // Old path for debugging
            if (file_exists($alternativePath)) {
                error_log("INFO: Found balance file at alternative path: $alternativePath");
            }
        } else {
            error_log("INFO: Balance file exists for user_id: $userId");
        }
    }
    
    /**
     * Create profile file if it doesn't exist
     */
    private function ensureProfileFileExists($userId, $username, $email) {
        $profileFile = USERDATA_DIR . "/profiles/{$userId}.txt";
        
        if (!file_exists($profileFile)) {
            $profileData = [
                'user_id' => $userId,
                'level' => 1, // Starting level
                'username' => $username,
                'email' => $email,
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
                'last_profile_update' => time()
            ];
            
            // Ensure directory exists
            $dir = dirname($profileFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            file_put_contents($profileFile, json_encode($profileData, JSON_PRETTY_PRINT));
            error_log("Created profile file for user_id: $userId with username: $username");
        }
    }
    
    /**
     * Handle guest/unauthenticated users
     */
    private function handleGuestUser() {
        $_SESSION['authenticated'] = false;
        $_SESSION['guest_mode'] = true;
        
        // Guest users can still have local balance for demo
        $_SESSION['balance'] = $_SESSION['balance'] ?? 0.0;
        
        return false;
    }
    
    /**
     * Handle invalid/expired tokens
     */
    private function handleInvalidToken() {
        // Clear authentication but keep any guest data
        unset($_SESSION['authenticated']);
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        unset($_SESSION['email']);
        
        return $this->handleGuestUser();
    }
    
    /**
     * Initialize session structure
     */
    private function initializeSession() {
        if (!isset($_SESSION['session_initialized'])) {
            $_SESSION['session_initialized'] = true;
            $_SESSION['session_started'] = time();
        }
    }
    
    /**
     * Base64 URL encode (for JWT)
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode (for JWT)
     */
    private function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    /**
     * Load user profile data including roles
     */
    private function loadUserProfile($userId, $username) {
        // Construct combined user ID for file lookup
        $combinedUserId = $userId . '-' . $username;
        $profileFile = USERDATA_DIR . "/profiles/{$combinedUserId}.txt";
        
        if (file_exists($profileFile)) {
            $profileData = json_decode(file_get_contents($profileFile), true);
            if ($profileData && is_array($profileData)) {
                return $profileData;
            }
        }
        
        // Try without combined format (legacy users)
        $legacyProfileFile = USERDATA_DIR . "/profiles/{$userId}-{$username}.txt";
        if (file_exists($legacyProfileFile)) {
            $profileData = json_decode(file_get_contents($legacyProfileFile), true);
            if ($profileData && is_array($profileData)) {
                return $profileData;
            }
        }
        
        // Default profile for new users
        return [
            'user_id' => $combinedUserId,
            'username' => $username,
            'roles' => ['member'],
            'joined_date' => time()
        ];
    }
    
    /**
     * Cache user roles in localStorage for instant client-side access
     * This enables zero-API-call role detection on the frontend
     */
    private function cacheRolesForClient($roles) {
        // Store roles in PHP session for now
        // The frontend will need to get roles via an API call or different method
        $_SESSION['cached_roles'] = $roles;
        
        // Note: JavaScript injection doesn't work here because this runs during 
        // JWT validation, not during page rendering. We'll need the frontend 
        // to get roles after login.
    }
    
    /**
     * Get current user info including roles
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email' => $_SESSION['email'],
            'balance' => $_SESSION['balance'],
            'roles' => $_SESSION['user_roles'] ?? ['member'],
            'authenticated' => true
        ];
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated() {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'];
    }
    
    /**
     * Update balance (instant, local)
     */
    public function updateBalance($amount, $operation = 'add') {
        switch ($operation) {
            case 'add':
                $_SESSION['balance'] += $amount;
                break;
            case 'subtract':
                $_SESSION['balance'] = max(0, $_SESSION['balance'] - $amount);
                break;
            case 'set':
                $_SESSION['balance'] = $amount;
                break;
        }
        
        // Mark for sync
        $this->markDirty('balance');
        
        return $_SESSION['balance'];
    }
    
    /**
     * Mark field as dirty for sync
     */
    private function markDirty($field) {
        if (!in_array($field, $_SESSION['dirty_fields'])) {
            $_SESSION['dirty_fields'][] = $field;
        }
        $_SESSION['last_modified'] = time();
    }
    
    /**
     * Get balance (instant, from session)
     */
    public function getBalance() {
        return $_SESSION['balance'] ?? 0.0;
    }
}

// Usage example:
/*
$bridge = new SessionBridge();
$authenticated = $bridge->authenticateUser();

if ($authenticated) {
    echo "Welcome " . $_SESSION['username'] . "!";
    echo "Balance: " . $bridge->getBalance();
} else {
    echo "Please login";
}
*/
?>
