/**
 * ROFLFaucet User Role System
 * 
 * Extensible role management system using username suffixes
 * Current roles: -r (recipient), -a (admin)
 * Future-ready for: -m (moderator), -s (staff), etc.
 */

class UserRoleSystem {
    constructor() {
        // Role definitions - easily extensible
        this.roleDefinitions = {
            'r': {
                name: 'Recipient',
                description: 'Can create and manage fundraising projects',
                permissions: ['create_projects', 'edit_own_projects', 'receive_donations'],
                uiElements: ['fundraiser-section', 'project-creation-tools']
            },
            'a': {
                name: 'Admin',
                description: 'Full site administration capabilities',
                permissions: ['verify_recipients', 'approve_projects', 'manage_users', 'access_admin_panel'],
                uiElements: ['admin-panel', 'verification-tools', 'project-approval-tools', 'admin-only']
            },
            // Future roles can be added here:
            // 'm': {
            //     name: 'Moderator',
            //     description: 'Content moderation and user support',
            //     permissions: ['moderate_content', 'assist_users', 'view_reports'],
            //     uiElements: ['moderation-panel', 'user-support-tools']
            // }
        };
        
        this.currentUser = this.getCurrentUser();
        this.userRoles = [];
        
        console.log('👤 Role System initialized for user:', this.currentUser?.username || 'guest');
        
        // Initialize roles asynchronously
        this.initializeRoles();
    }
    
    async initializeRoles() {
        if (!this.currentUser?.username) {
            this.userRoles = [];
            console.log('🏷️ User roles: [] (guest user)');
            return;
        }
        
        // Try to get cached roles first
        const cachedRoles = this.getCachedRoles();
        if (cachedRoles.length > 0) {
            this.userRoles = cachedRoles;
            console.log('🏷️ User roles:', this.userRoles, '(cached)');
            this.updateUIBasedOnRoles();
            return;
        }
        
        // Fetch roles from profile API and cache them
        try {
            const combinedUserId = this.currentUser.combined_user_id || `${this.currentUser.user_id}-${this.currentUser.username}`;
            const response = await fetch(`api/simple-profile.php?action=profile&user_id=${combinedUserId}`);
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.profile && data.profile.roles) {
                    // Cache roles in localStorage
                    localStorage.setItem('user_roles', JSON.stringify(data.profile.roles));
                    
                    // Convert to role keys and store
                    this.userRoles = data.profile.roles.map(role => this.mapProfileRoleToKey(role)).filter(Boolean);
                    console.log('🏷️ User roles:', this.userRoles, '(from profile)');
                    
                    // Update UI now that we have roles
                    this.updateUIBasedOnRoles();
                } else {
                    console.log('🏷️ No roles found in profile, defaulting to member');
                    this.userRoles = [];
                }
            } else {
                console.warn('⚠️ Profile API request failed:', response.status);
                this.userRoles = [];
            }
        } catch (error) {
            console.error('💥 Error fetching profile roles:', error);
            this.userRoles = [];
        }
    }
    
    getCurrentUser() {
        try {
            const sessionData = localStorage.getItem('directsponsor_session');
            if (sessionData) {
                const data = JSON.parse(sessionData);
                if (data.expires && Date.now() > data.expires) {
                    return null; // Expired session
                }
                return {
                    username: data.username,
                    user_id: data.user_id,
                    combined_user_id: data.combined_user_id
                };
            }
            
            // Fallback to individual localStorage items
            const username = localStorage.getItem('username');
            const user_id = localStorage.getItem('user_id');
            if (username && user_id) {
                return {
                    username: username,
                    user_id: user_id,
                    combined_user_id: `${user_id}-${username}`
                };
            }
            
            return null;
        } catch (error) {
            console.error('Error getting current user:', error);
            return null;
        }
    }
    
    getUserRoles() {
        // Get roles from session bridge cache (set during login)
        return this.getCachedRoles();
    }
    
    getCachedRoles() {
        try {
            const cachedRoles = localStorage.getItem('user_roles');
            if (cachedRoles) {
                const roles = JSON.parse(cachedRoles);
                // Convert profile role names to role keys
                return roles.map(role => this.mapProfileRoleToKey(role)).filter(Boolean);
            }
        } catch (error) {
            console.error('Error reading cached roles:', error);
        }
        return [];
    }
    
    mapProfileRoleToKey(profileRole) {
        const mapping = {
            'recipient': 'r',
            'admin': 'a', 
            'moderator': 'm'
        };
        return mapping[profileRole.toLowerCase()] || null;
    }
    
    hasRole(roleKey) {
        return this.userRoles.includes(roleKey);
    }
    
    hasPermission(permission) {
        return this.userRoles.some(role => 
            this.roleDefinitions[role]?.permissions.includes(permission)
        );
    }
    
    getRoleNames() {
        return this.userRoles.map(role => this.roleDefinitions[role]?.name).filter(Boolean);
    }
    
    isRecipient() {
        return this.hasRole('r');
    }
    
    isAdmin() {
        return this.hasRole('a');
    }
    
    // Future role checks can be added easily:
    // isModerator() {
    //     return this.hasRole('m');
    // }
    
    getBaseUsername() {
        if (!this.currentUser?.username) {
            return null;
        }
        
        let baseUsername = this.currentUser.username;
        
        // Remove all known role suffixes
        Object.keys(this.roleDefinitions).forEach(roleSuffix => {
            const suffix = `-${roleSuffix}`;
            if (baseUsername.endsWith(suffix)) {
                baseUsername = baseUsername.slice(0, -suffix.length);
            }
        });
        
        return baseUsername;
    }
    
    validateUsername(username) {
        // Check if username contains any role suffixes
        const hasRoleSuffix = Object.keys(this.roleDefinitions).some(roleSuffix => 
            username.includes(`-${roleSuffix}`)
        );
        
        if (hasRoleSuffix) {
            return {
                valid: false,
                error: 'Username cannot contain role identifiers (-r, -a, etc.)'
            };
        }
        
        return { valid: true };
    }
    
    updateUIBasedOnRoles() {
        if (!this.currentUser) {
            return;
        }
        
        // Show/hide elements based on roles
        this.userRoles.forEach(role => {
            const roleConfig = this.roleDefinitions[role];
            if (roleConfig?.uiElements) {
                roleConfig.uiElements.forEach(elementClass => {
                    const elements = document.querySelectorAll(`.${elementClass}`);
                    elements.forEach(element => {
                        element.style.display = 'block';
                        element.classList.add('role-enabled');
                    });
                });
            }
        });
        
        // Add role indicators to profile
        this.addRoleIndicators();
        
        console.log('🎨 UI updated based on user roles:', this.getRoleNames());
    }
    
    addRoleIndicators() {
        // Add role badges to profile/navigation
        const roleContainer = document.getElementById('user-role-indicators');
        if (roleContainer && this.userRoles.length > 0) {
            roleContainer.innerHTML = this.userRoles.map(role => {
                const roleConfig = this.roleDefinitions[role];
                return `<span class="role-badge role-${role}" title="${roleConfig.description}">${roleConfig.name}</span>`;
            }).join('');
        }
    }
    
    // Admin functions
    createRecipientUsername(baseUsername) {
        if (!this.hasPermission('verify_recipients')) {
            throw new Error('Permission denied: Cannot create recipient usernames');
        }
        
        return `${baseUsername}-r`;
    }
    
    // Utility functions for future expansion
    addRole(username, roleKey) {
        if (!this.hasPermission('manage_users')) {
            throw new Error('Permission denied: Cannot modify user roles');
        }
        
        if (!this.roleDefinitions[roleKey]) {
            throw new Error(`Unknown role: ${roleKey}`);
        }
        
        // This would integrate with backend API to modify user data
        console.log(`Would add role ${roleKey} to user ${username}`);
    }
    
    removeRole(username, roleKey) {
        if (!this.hasPermission('manage_users')) {
            throw new Error('Permission denied: Cannot modify user roles');
        }
        
        // This would integrate with backend API to modify user data
        console.log(`Would remove role ${roleKey} from user ${username}`);
    }
}

// Global instance
window.userRoles = new UserRoleSystem();

// Global convenience functions
window.hasRole = (role) => window.userRoles.hasRole(role);
window.hasPermission = (permission) => window.userRoles.hasPermission(permission);
window.isRecipient = () => window.userRoles.isRecipient();
window.isAdmin = () => window.userRoles.isAdmin();
window.validateUsername = (username) => window.userRoles.validateUsername(username);

// Auto-update UI when page loads
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        window.userRoles.updateUIBasedOnRoles();
    }, 100);
});

console.log('👤 User Role System ready!');