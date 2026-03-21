<?php
// project-management-api-v2.php - Simplified API for contenteditable approach
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Read raw input - POST requests only for security
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'get_project':
            $projectId = $input['project_id'] ?? '';
            $username = $input['username'] ?? '';
            
            // Require authentication to load project data for editing
            if (empty($username)) {
                echo json_encode(['success' => false, 'error' => 'Username required']);
                break;
            }
            
            // Check if user can edit this project before showing data
            if (!canUserEditProject($username, $projectId)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                break;
            }
            
            echo json_encode(getProjectData($projectId));
            break;
            
        case 'update_project':
            echo json_encode(updateProject($input));
            break;
            
    case 'create_project':
        echo json_encode(createProject($input));
        break;
    
    case 'create_draft_project':
        echo json_encode(createDraftProject($input));
        break;
    
    case 'list_user_projects':
        echo json_encode(listUserProjects($input));
        break;
    
    case 'get_project_public':
        // Public endpoint - no auth required
        $projectId = $input['project_id'] ?? '';
        echo json_encode(getProjectData($projectId));
        break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getProjectData($projectId) {
    // Search all user directories for this project ID
    $userDirs = glob('/var/www/directsponsor.net/userdata/projects/*', GLOB_ONLYDIR);
    
    $fileToRead = null;
    $username = null;
    
    foreach ($userDirs as $userDir) {
        $userBasename = basename($userDir);
        
        // Check active directory first
        $activeFile = "{$userDir}/active/{$projectId}.html";
        if (file_exists($activeFile)) {
            $fileToRead = $activeFile;
            $username = $userBasename;
            break;
        }
        
        // Check completed directory
        $completedFile = "{$userDir}/completed/{$projectId}.html";
        if (file_exists($completedFile)) {
            $fileToRead = $completedFile;
            $username = $userBasename;
            break;
        }
    }
    
    if (!$fileToRead) {
        return ['success' => false, 'error' => 'Project not found'];
    }
    
    $html = file_get_contents($fileToRead);
    if ($html === false) {
        return ['success' => false, 'error' => 'Failed to read project file'];
    }
    
    // Extract content directly - no conversion needed for contenteditable
    $targetAmountRaw = extractByComments($html, 'target-amount', '50000');
    
    error_log('DEBUG target_amount_raw: ' . $targetAmountRaw);
    
    // Use Apache Alias path for images (served from protected directory)
    $imageUrl = "/project-images/{$username}/images/{$projectId}.jpg";
    $imagePath = "/var/www/directsponsor.net/userdata/projects/{$username}/images/{$projectId}.jpg";
    
    if (!file_exists($imagePath) && $username) {
        // Copy placeholder to user directory
        $placeholderPath = '/var/www/html/images/placeholder.jpg';
        if (file_exists($placeholderPath)) {
            // Ensure user images directory exists
            $userImagesDir = "/var/www/directsponsor.net/userdata/projects/{$username}/images";
            if (!is_dir($userImagesDir)) {
                mkdir($userImagesDir, 0755, true);
            }
            // Copy placeholder as project image
            copy($placeholderPath, $imagePath);
        }
    }
    
    error_log('DEBUG image URL: ' . $imageUrl);
    
    $projectData = [
        'id' => $projectId,
        'filename' => basename($fileToRead), // Include actual filename for correct links
        'title' => extractByComments($html, 'title', 'Untitled Project'),
        'description' => extractByComments($html, 'description', ''),
        'full_description' => extractByComments($html, 'full-description', ''),
        'recipient_name' => extractByComments($html, 'recipient-name', ''),
        'location' => extractByComments($html, 'location', ''),
        'website_url' => extractByComments($html, 'website-url', ''),
        'target_amount' => (int)str_replace(',', '', $targetAmountRaw),
        'current_amount' => (int)str_replace(',', '', extractByComments($html, 'current-amount', '0')),
        'supporters_count' => 0,
        'category' => 'General',
        'status' => 'active',
        'main_image' => $imageUrl, // Use actual image or placeholder
        'created_date' => '2024-10-13'
    ];
    
    return ['success' => true, 'data' => $projectData];
}

function extractByComments($html, $tag, $default = '') {
    $pattern = '/<!-- ' . preg_quote($tag, '/') . ' -->(.*?)<!-- end ' . preg_quote($tag, '/') . ' -->/s';
    if (preg_match($pattern, $html, $matches)) {
        return trim($matches[1]);
    }
    return $default;
}

function updateByComments($html, $tag, $newContent) {
    $pattern = '/<!-- ' . preg_quote($tag, '/') . ' -->.*?<!-- end ' . preg_quote($tag, '/') . ' -->/s';
    $replacement = '<!-- ' . $tag . ' -->' . $newContent . '<!-- end ' . $tag . ' -->';
    return preg_replace($pattern, $replacement, $html);
}

// getProjectImageUrl function removed - now using simple JPG-only approach

function updateProject($data) {
    $projectId = $data['project_id'] ?? '';
    $username = $data['username'] ?? '';
    
    // Basic validation
    if (empty($projectId)) {
        return ['success' => false, 'error' => 'Project ID required'];
    }
    
    if (empty($data['title'])) {
        return ['success' => false, 'error' => 'Title is required'];
    }
    
    if (strlen($data['title']) > 80) {
        return ['success' => false, 'error' => 'Title must be 80 characters or less for better URLs'];
    }
    
    if (empty($data['description'])) {
        return ['success' => false, 'error' => 'Description is required'];
    }
    
    if (!is_numeric($data['target_amount']) || $data['target_amount'] < 1000) {
        return ['success' => false, 'error' => 'Target amount must be at least 1000 sats'];
    }
    
    if (empty($data['location']) || trim($data['location']) === '') {
        return ['success' => false, 'error' => 'Location is required'];
    }
    
    // Check if user can edit this project (handles both active and pending)
    if (!canUserEditProject($username, $projectId)) {
        return ['success' => false, 'error' => 'Access denied: You can only edit your own projects'];
    }
    
    // Prepare data for update - much simpler with contenteditable!
    $updateData = [
        'title' => htmlspecialchars($data['title']),
        'description' => htmlspecialchars($data['description']),
        'full_description' => $data['full_description'], // Keep HTML as-is from contenteditable
        'target_amount' => (int)$data['target_amount'],
        'recipient_name' => htmlspecialchars($data['recipient_name'] ?? ''),
        'location' => htmlspecialchars($data['location']),
        'website_url' => htmlspecialchars($data['website_url'] ?? ''),
        // main_image removed - using convention-based naming
    ];
    
    // Ensure project image exists (copy placeholder if no image uploaded)
    $imageUrl = '/images/projects/project-' . $projectId . '.jpg';
    $imagePath = '/var/www/html' . $imageUrl;
    
    if (!file_exists($imagePath)) {
        // Copy placeholder to project-specific name
        $placeholderPath = '/var/www/html/images/placeholder.jpg';
        if (file_exists($placeholderPath)) {
            // Ensure projects directory exists
            $projectsDir = '/var/www/html/images/projects';
            if (!is_dir($projectsDir)) {
                mkdir($projectsDir, 0755, true);
            }
            // Copy placeholder as project image
            copy($placeholderPath, $imagePath);
        }
    }
    
    // Update the actual HTML file and get the current filename
    $updateResult = updateProjectHtmlFile($projectId, $updateData);
    
    if ($updateResult) {
        // Get the actual current filename and location for the view link
        $projectLocation = getProjectLocation($projectId);
        $currentFilename = getCurrentProjectFilename($projectId);
        
        return [
            'success' => true, 
            'message' => 'Project updated successfully and changes are now live!',
            'filename' => $currentFilename,
            'project_url' => '/projects/' . $projectLocation . $currentFilename
        ];
    } else {
        return [
            'success' => false, 
            'error' => 'Failed to update project file'
        ];
    }
}

function updateProjectHtmlFile($projectId, $projectData) {
    // Search all user directories for this project ID
    $userDirs = glob('/var/www/directsponsor.net/userdata/projects/*', GLOB_ONLYDIR);
    
    $fileToUpdate = null;
    $username = null;
    
    foreach ($userDirs as $userDir) {
        // Check active directory first
        $activeFile = "{$userDir}/active/{$projectId}.html";
        if (file_exists($activeFile)) {
            $fileToUpdate = $activeFile;
            $username = basename($userDir);
            break;
        }
        
        // Check completed directory
        $completedFile = "{$userDir}/completed/{$projectId}.html";
        if (file_exists($completedFile)) {
            $fileToUpdate = $completedFile;
            $username = basename($userDir);
            break;
        }
    }
    
    if (!$fileToUpdate) {
        error_log("Project file not found for ID: {$projectId}");
        return false;
    }
    
    $html = file_get_contents($fileToUpdate);
    if ($html === false) {
        return false;
    }
    
    // Update content using comment markers
    $html = updateByComments($html, 'title', $projectData['title']);
    $html = updateByComments($html, 'description', $projectData['description']);
    $html = updateByComments($html, 'full-description', $projectData['full_description']);
    $html = updateByComments($html, 'recipient-name', $projectData['recipient_name']);
    $html = updateByComments($html, 'location', $projectData['location']);
    $html = updateByComments($html, 'website-url', $projectData['website_url']);
    $html = updateByComments($html, 'target-amount', number_format($projectData['target_amount']));
    
    // Use Apache Alias path for image URL
    $imageUrl = "/project-images/{$username}/images/{$projectId}.jpg";
    $imageHtml = '<div class="project-main-image" style="margin-bottom: 20px; text-align: center;"><img src="' . $imageUrl . '" alt="Project Image" style="max-width: 100%; max-height: 400px; object-fit: contain; border-radius: 8px;"></div>';
    $html = updateByComments($html, 'main-image', $imageHtml);
    
    // Write the updated HTML back to the file
    $result = file_put_contents($fileToUpdate, $html);
    
    return $result !== false;
}

function getProjectLocation($projectId) {
    // Search all user directories for this project
    $userDirs = glob('/var/www/directsponsor.net/userdata/projects/*', GLOB_ONLYDIR);
    
    foreach ($userDirs as $userDir) {
        $username = basename($userDir);
        
        // Check active directory
        $activeFile = "{$userDir}/active/{$projectId}.html";
        if (file_exists($activeFile)) {
            return "{$username}/active/"; // User active directory
        }
        
        // Check completed directory
        $completedFile = "{$userDir}/completed/{$projectId}.html";
        if (file_exists($completedFile)) {
            return "{$username}/completed/"; // User completed directory
        }
    }
    
    // Fallback - assume active
    return '';
}

function getCurrentProjectFilename($projectId) {
    // All projects now use simple numeric format in user directories
    return "{$projectId}.html";
}

function canUserEditProject($username, $projectId) {
    // Clean username for comparison
    $cleanUsername = (strpos($username, '-') !== false) ? explode('-', $username)[1] : $username;
    
    // Check if project exists in user's directory
    $userActiveFile = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/active/{$projectId}.html";
    $userCompletedFile = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/completed/{$projectId}.html";
    
    // User can edit if project exists in their directory
    return file_exists($userActiveFile) || file_exists($userCompletedFile);
}


function createProject($data) {
    $username = $data['username'] ?? '';
    
    // Basic validation
    if (empty($username)) {
        return ['success' => false, 'error' => 'Username required'];
    }
    
    if (empty($data['title'])) {
        return ['success' => false, 'error' => 'Title is required'];
    }
    
    if (strlen($data['title']) > 80) {
        return ['success' => false, 'error' => 'Title must be 80 characters or less for better URLs'];
    }
    
    if (empty($data['description'])) {
        return ['success' => false, 'error' => 'Description is required'];
    }
    
    if (!is_numeric($data['target_amount']) || $data['target_amount'] < 1000) {
        return ['success' => false, 'error' => 'Target amount must be at least 1000 sats'];
    }
    
    if (empty($data['location']) || trim($data['location']) === '') {
        return ['success' => false, 'error' => 'Location is required'];
    }
    
    // Generate next project ID
    $projectId = getNextProjectId();
    
    // Use simple numeric filename
    $urlSlug = $projectId . '.html';
    
    // Prepare project data
    $projectData = [
        'title' => htmlspecialchars($data['title']),
        'description' => htmlspecialchars($data['description']),
        'full_description' => $data['full_description'] ?? '<p>Full project description...</p>',
        'target_amount' => (int)$data['target_amount'],
        'recipient_name' => htmlspecialchars($data['recipient_name'] ?? $username),
        'location' => htmlspecialchars($data['location']),
        'website_url' => htmlspecialchars($data['website_url'] ?? ''),
        'category' => htmlspecialchars($data['category'] ?? 'General'),
        'current_amount' => 0,
        'supporters_count' => 0,
        'status' => 'active',
        'url_slug' => $urlSlug
    ];
    
    // Create project HTML file from template (active directory only)
    $success = createProjectHtmlFile($projectId, $projectData, $username, $urlSlug, true);
    
    // Ensure project image exists (copy placeholder if no image uploaded)
    $imageUrl = '/images/projects/project-' . $projectId . '.jpg';
    $imagePath = '/var/www/html' . $imageUrl;
    
    if (!file_exists($imagePath)) {
        // Copy placeholder to project-specific name
        $placeholderPath = '/var/www/html/images/placeholder.jpg';
        if (file_exists($placeholderPath)) {
            // Ensure projects directory exists
            $projectsDir = '/var/www/html/images/projects';
            if (!is_dir($projectsDir)) {
                mkdir($projectsDir, 0755, true);
            }
            // Copy placeholder as project image
            copy($placeholderPath, $imagePath);
        }
    }
    
    if ($success) {
        return [
            'success' => true,
            'message' => 'Project created successfully and is now live in the queue!',
            'project_id' => $projectId,
            'project_url' => null,
            'filename' => $urlSlug,
            'pending_location' => '/var/www/directsponsor.net/userdata/projects/pending/' . $urlSlug
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to create project file'
        ];
    }
}

function createDraftProject($data) {
    $username = $data['username'] ?? '';
    
    // Basic validation
    if (empty($username)) {
        return ['success' => false, 'error' => 'Username required'];
    }
    
    // Generate next project ID (real ID, not temporary)
    $projectId = getNextProjectId();
    
    // Create minimal placeholder project data
    $projectData = [
        'title' => 'New Project (In Progress)',
        'description' => 'Project details being added...',
        'full_description' => '<p>Project description being prepared...</p>',
        'target_amount' => 1000,
        'recipient_name' => $username,
        'website_url' => '',
        'category' => 'General',
        'current_amount' => 0,
        'supporters_count' => 0,
        'status' => 'active',
        'url_slug' => $projectId . '.html'
    ];
    
    // Create the actual project file in user's active directory
    $success = createProjectHtmlFile($projectId, $projectData, $username, $projectData['url_slug']);
    
    // Ensure project image exists in user directory
    $cleanUsername = (strpos($username, '-') !== false) ? explode('-', $username)[1] : $username;
    $userImagesDir = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/images";
    $imagePath = "{$userImagesDir}/{$projectId}.jpg";
    
    if (!file_exists($imagePath)) {
        // Copy placeholder to user's images directory
        $placeholderPath = '/var/www/html/images/placeholder.jpg';
        if (file_exists($placeholderPath)) {
            // Ensure user images directory exists
            if (!is_dir($userImagesDir)) {
                mkdir($userImagesDir, 0755, true);
            }
            // Copy placeholder as project image
            copy($placeholderPath, $imagePath);
        }
    }
    
    if ($success) {
        return [
            'success' => true,
            'message' => 'Project created successfully and is now live!',
            'project_id' => $projectId,
            'filename' => $projectData['url_slug']
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to create project file'
        ];
    }
}

function getNextProjectId() {
    $dataDir = '/var/www/directsponsor.net/userdata/projects';
    $existingIds = [];
    
    // Find all existing project IDs - check both active and pending directories
    $dirsToCheck = [$dataDir, $dataDir . '/pending', $dataDir . '/completed'];
    
    foreach ($dirsToCheck as $dir) {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                $id = 0;
                
                // Simple numeric format: 001.html
                if (preg_match('/^(\d+)\.html$/', $file, $matches)) {
                    $id = (int)$matches[1];
                }
                // New SEO format: 001-project-keywords.html (digits at start, then dash)
                elseif (preg_match('/^(\d+)-.*\.html$/', $file, $matches)) {
                    $id = (int)$matches[1];
                }
                // Legacy format: project-001.html
                elseif (preg_match('/^project-(\d+)\.html$/', $file, $matches)) {
                    $id = (int)$matches[1];
                }
                
                if ($id > 0) {
                    $existingIds[] = $id;
                }
            }
        }
    }
    
    // Sort the existing IDs to find the first gap
    sort($existingIds);
    
    // Find the first available slot starting from 1
    $nextId = 1;
    foreach ($existingIds as $existingId) {
        if ($existingId == $nextId) {
            $nextId++;
        } else {
            // Found a gap, use this slot
            break;
        }
    }
    
    return str_pad($nextId, 3, '0', STR_PAD_LEFT); // Returns 001, 002, 003, etc.
}

function createProjectHtmlFile($projectId, $projectData, $username, $urlSlug) {
    $templateFile = '/var/www/html/project-template.html';
    
    // Clean username and create user directory structure
    $cleanUsername = (strpos($username, '-') !== false) ? explode('-', $username)[1] : $username;
    $userActiveDir = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/active";
    
    // Ensure user directories exist
    if (!is_dir($userActiveDir)) {
        mkdir($userActiveDir, 0755, true);
        mkdir("/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/completed", 0755, true);
        mkdir("/var/www/directsponsor.net/userdata/projects/{$cleanUsername}/images", 0755, true);
    }
    
    $outputFile = "{$userActiveDir}/{$projectId}.html";
    
    // Read template
    if (!file_exists($templateFile)) {
        return false;
    }
    
    $html = file_get_contents($templateFile);
    if ($html === false) {
        return false;
    }
    
    // Replace template content with project data
    $html = updateByComments($html, 'title', $projectData['title']);
    $html = updateByComments($html, 'description', $projectData['description']);
    $html = updateByComments($html, 'full-description', $projectData['full_description']);
    $html = updateByComments($html, 'recipient-name', $projectData['recipient_name']);
    $html = updateByComments($html, 'website-url', $projectData['website_url']);
    $html = updateByComments($html, 'target-amount', number_format($projectData['target_amount']));
    $html = updateByComments($html, 'current-amount', number_format($projectData['current_amount']));
    $html = updateByComments($html, 'supporters-count', $projectData['supporters_count']);
    $html = updateByComments($html, 'category', $projectData['category']);
    $html = updateByComments($html, 'status', $projectData['status']);
    $html = updateByComments($html, 'url-slug', $urlSlug);
    
    // Add main image using user directory structure
    $standardImageUrl = "/projects/{$cleanUsername}/images/{$projectId}.jpg";
    $imageHtml = '<div class="project-main-image" style="margin-bottom: 20px; text-align: center;"><img src="' . $standardImageUrl . '" alt="Project Image" style="max-width: 100%; max-height: 400px; object-fit: contain; border-radius: 8px;"></div>';
    $html = updateByComments($html, 'main-image', $imageHtml);
    
    // Add owner comment for permissions
    $html = "<!-- OWNER: {$username} -->\n" . $html;
    
    // Write project file using SEO-friendly filename
    $result = file_put_contents($outputFile, $html);
    
    return $result !== false;
}

function listUserProjects($data) {
    $username = $data['username'] ?? '';
    
    if (empty($username)) {
        return ['success' => false, 'error' => 'Username required'];
    }
    
    // Clean username
    $cleanUsername = (strpos($username, '-') !== false) ? explode('-', $username)[1] : $username;
    
    $userDir = "/var/www/directsponsor.net/userdata/projects/{$cleanUsername}";
    $projects = [];
    
    // Check if user directory exists
    if (!is_dir($userDir)) {
        return ['success' => true, 'projects' => []];
    }
    
    // Get projects from active directory
    $activeDir = "{$userDir}/active";
    if (is_dir($activeDir)) {
        $files = glob("{$activeDir}/*.html");
        foreach ($files as $file) {
            $projectId = basename($file, '.html');
            $html = file_get_contents($file);
            
            $projects[] = [
                'id' => $projectId,
                'title' => extractByComments($html, 'title', 'Untitled'),
                'tagline' => extractByComments($html, 'description', ''),
                'goal_amount' => (int)str_replace(',', '', extractByComments($html, 'target-amount', '0')),
                'current_amount' => (int)str_replace(',', '', extractByComments($html, 'current-amount', '0')),
                'category' => extractByComments($html, 'category', 'General'),
                'status' => 'active',
                'created_date' => filemtime($file),
                'is_pending' => false,
                'verification' => ['verified' => true]
            ];
        }
    }
    
    return ['success' => true, 'projects' => $projects];
}
?>
