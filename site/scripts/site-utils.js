// Site Utilities - Minimal Version
// Essential functions for faucet countdown system

// Cooldown functions
function getRemainingCooldownTime() {
    const lastClaim = localStorage.getItem('last_claim_time');
    if (!lastClaim) return 0;
    
    const now = Date.now();
    const cooldownTime = 5 * 60 * 1000; // 5 minutes
    const timeSinceLastClaim = now - parseInt(lastClaim);
    
    return Math.max(0, cooldownTime - timeSinceLastClaim);
}

function canClaim() {
    return getRemainingCooldownTime() === 0;
}

let faucetCountdownInterval = null;

function updateFaucetCountdownButton() {
    const btn = document.getElementById('faucet-countdown-btn');
    if (!btn) return;
    
    const btnText = btn.querySelector('.btn-text');
    if (!btnText) return;
    
    const totalCooldown = 5 * 60 * 1000; // 5 minutes in milliseconds
    const remaining = getRemainingCooldownTime();
    
    // Calculate progress percentage (how much time has passed)
    const elapsed = totalCooldown - remaining;
    const progressPercent = Math.max(0, Math.min(100, (elapsed / totalCooldown) * 100));
    
    if (remaining <= 0) {
        // Ready to claim - bright green gradient
        btn.disabled = false;
        btnText.textContent = 'Faucet: Ready';
        btn.style.background = 'linear-gradient(90deg, #3CE74C 0%, #2DD93C 50%, #3CE74C 100%)';
        
        // Clear interval if running
        if (faucetCountdownInterval) {
            clearInterval(faucetCountdownInterval);
            faucetCountdownInterval = null;
        }
    } else {
        // Still in cooldown - progress gradient
        btn.disabled = true;
        const seconds = Math.ceil(remaining / 1000);
        btnText.textContent = `Faucet: ${seconds}s`;
        
        // Progress bar effect - green fills from left as time passes
        btn.style.background = `linear-gradient(to right, #3CE74C ${progressPercent}%, #95a5a6 ${progressPercent}%)`;
    }
    
    // Update slot machine progress bars (progress-fill elements)
    const progressBar = document.getElementById('progress-fill');
    if (progressBar) {
        if (remaining <= 0) {
            // Ready to claim - show full progress
            progressBar.style.width = '100%';
            
            // Check if this is the first time reaching 100% (trigger red pulse)
            if (!progressBar.hasAttribute('data-pulsed')) {
                progressBar.setAttribute('data-pulsed', 'true');
                
                // Red pulse animation - 2 quick pulses
                progressBar.style.background = '#ff4757'; // Bright red
                progressBar.style.transition = 'background 0.3s ease';
                
                setTimeout(() => {
                    progressBar.style.background = 'linear-gradient(90deg, #3CE74C 0%, #2DD93C 50%, #3CE74C 100%)'; // Green
                    setTimeout(() => {
                        progressBar.style.background = '#ff4757'; // Red again
                        setTimeout(() => {
                            progressBar.style.background = 'linear-gradient(90deg, #3CE74C 0%, #2DD93C 50%, #3CE74C 100%)'; // Final green
                            progressBar.style.transition = ''; // Remove transition
                        }, 300);
                    }, 300);
                }, 300);
            } else {
                // Already pulsed, just show steady green
                progressBar.style.background = 'linear-gradient(90deg, #3CE74C 0%, #2DD93C 50%, #3CE74C 100%)';
            }
        } else {
            // In cooldown - show progress percentage
            progressBar.style.width = `${progressPercent}%`;
            progressBar.style.background = 'linear-gradient(90deg, #3CE74C 0%, #2DD93C 50%, #3CE74C 100%)';
            
            // Reset pulse flag when back in cooldown
            progressBar.removeAttribute('data-pulsed');
        }
    }
}

function startFaucetCountdown() {
    // Clear any existing interval
    if (faucetCountdownInterval) {
        clearInterval(faucetCountdownInterval);
    }
    
    // Update immediately
    updateFaucetCountdownButton();
    
    // Update every second
    faucetCountdownInterval = setInterval(updateFaucetCountdownButton, 1000);
}

function handleFaucetClaim() {
    if (!canClaim()) {
        alert('Faucet is not ready yet!');
        return;
    }
    
    // Determine where to redirect based on current page
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    
    if (currentPage === 'index.html' || currentPage === '') {
        // From main faucet page, go to claim page
        window.location.href = 'faucet-claim.html';
    } else if (currentPage === 'faucet-result.html') {
        // From result page, go back to main faucet page
        window.location.href = 'index.html';
    } else {
        // Default fallback to main page
        window.location.href = 'index.html';
    }
}

// Mobile navigation toggle
function toggleMobileNav() {
    const nav = document.getElementById('main-nav');
    if (nav) {
        nav.classList.toggle('mobile-nav-open');
    }
}

// Simple login handler - dynamically builds redirect URL
function handleLogin() {
    // Get current page URL dynamically (works for staging, production, any domain)
    const currentUrl = window.location.origin + window.location.pathname;
    
    // Build auth URL with dynamic redirect
    const authUrl = `https://auth.directsponsor.org/jwt-login.php?redirect_uri=${encodeURIComponent(currentUrl)}`;
    
    console.log('🚀 Redirecting to login:', authUrl);
    
    // Go to auth server
    window.location.href = authUrl;
}

// Check if logged in as system user and show warning
function checkSystemUserWarning() {
    const username = getValidUsername();
    if (username && username.toLowerCase() === 'system') {
        showSystemUserWarning();
    }
}

// Show persistent system user warning banner
function showSystemUserWarning() {
    // Remove existing warning if any
    const existing = document.getElementById('systemUserWarning');
    if (existing) existing.remove();
    
    // Create warning banner
    const warning = document.createElement('div');
    warning.id = 'systemUserWarning';
    warning.innerHTML = `
        <div style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            color: white;
            padding: 12px 20px;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            z-index: 10000;
            border-bottom: 3px solid #e85a2b;
        ">
            🤖 SYSTEM USER MODE - Use ONLY for distributing operating funds TO recipients (Evans, etc.) - NOT for Project 000 or manual recording!
        </div>
    `;
    
    // Add to page
    document.body.insertBefore(warning, document.body.firstChild);
    
    // Adjust page content to account for banner
    document.body.style.paddingTop = '60px';
}

// Auto-check for system user warning on page load
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure DOM is fully ready
    setTimeout(checkSystemUserWarning, 100);
});

// Also check when page becomes visible (for navigation)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        checkSystemUserWarning();
    }
});

// Helper function to get valid (non-expired) username
function getValidUsername() {
    try {
        // Check the main session data first
        const sessionData = localStorage.getItem('directsponsor_session');
        if (sessionData) {
            const data = JSON.parse(sessionData);
            // Check if session has expired
            if (data.expires && Date.now() > data.expires) {
                console.log('🔐 Session expired, logging out automatically');
                handleLogout();
                return null;
            }
            return data.username;
        }
        
        // Fallback to individual username item
        return localStorage.getItem('username');
    } catch (error) {
        console.error('Error checking login status:', error);
        return localStorage.getItem('username'); // Fallback
    }
}

// Helper function to get valid user ID (numeric only)
function getValidUserId() {
    try {
        const sessionData = localStorage.getItem('directsponsor_session');
        if (sessionData) {
            const data = JSON.parse(sessionData);
            if (data.expires && Date.now() > data.expires) {
                return null; // Expired
            }
            return data.user_id;
        }
        return localStorage.getItem('user_id');
    } catch (error) {
        return localStorage.getItem('user_id');
    }
}

// Helper function to get combined user ID (for file access)
function getCombinedUserId() {
    try {
        const sessionData = localStorage.getItem('directsponsor_session');
        if (sessionData) {
            const data = JSON.parse(sessionData);
            if (data.expires && Date.now() > data.expires) {
                return null; // Expired
            }
            return data.combined_user_id;
        }
        return localStorage.getItem('combined_user_id');
    } catch (error) {
        return localStorage.getItem('combined_user_id');
    }
}

// Simple logout handler - clears session and refreshes page
function handleLogout() {
    console.log('🚪 Logging out...');
    
    // Clear all login storage
    localStorage.removeItem('directsponsor_session');
    localStorage.removeItem('username');
    localStorage.removeItem('user_id');
    localStorage.removeItem('login_time');
    
    // Refresh the unified balance system
    if (window.unifiedBalance) {
        window.unifiedBalance.refreshLoginStatus();
    }
    
    // Update the UI
    updateLoginButtons();
    
    // Update balance and currency displays
    setTimeout(() => {
        if (window.updateBalanceDisplays) window.updateBalanceDisplays();
        if (window.updateCurrencyDisplays) window.updateCurrencyDisplays();
    }, 100);
    
    console.log('✅ Logged out successfully');
}

// Simple login state management - handle desktop, mobile nav, and member elements
function updateLoginButtons() {
    const username = getValidUsername();
    const isLoggedIn = !!username;
    
    // Hide/show guest-only elements (this was already working)
    const guestOnlyElements = document.querySelectorAll('.guest-only');
    guestOnlyElements.forEach(element => {
        element.style.display = isLoggedIn ? 'none' : '';
    });
    
    // Show/hide member-only elements
    const memberOnlyElements = document.querySelectorAll('.member-only');
    memberOnlyElements.forEach(element => {
        element.style.display = isLoggedIn ? '' : 'none';
    });
    
    // Update username in dropdown button (show display name if available)
    const userMenuButton = document.getElementById('user-menu-button');
    if (userMenuButton && isLoggedIn) {
        updateUserMenuDisplayName(userMenuButton, username);
    }
    
    // Handle mobile navigation - update existing buttons or create new ones
    updateMobileNavigation(isLoggedIn, username);
    
    // Load notifications for logged in users
    if (isLoggedIn) {
        loadNotifications();
    }
    
    console.log(`👁️ Login state: ${isLoggedIn ? `Logged in as ${username}` : 'Guest'}`);
}

// Handle mobile navigation login/logout buttons
let lastMobileNavState = null;
function updateMobileNavigation(isLoggedIn, username) {
    const mobileNav = document.querySelector('.mobile-nav');
    if (!mobileNav) return; // No mobile nav on this page
    
    // Prevent duplicate updates with same state
    const currentState = `${isLoggedIn}-${username}`;
    if (lastMobileNavState === currentState) {
        return; // Skip duplicate update
    }
    lastMobileNavState = currentState;
    
    // Remove any existing auth buttons
    const existingAuthButtons = mobileNav.querySelectorAll('a.nav-button');
    existingAuthButtons.forEach(btn => {
        const text = btn.textContent || btn.innerHTML;
        if (text.includes('🚪 Login') || text.includes('🚪 Logout') || btn.href.includes('auth.directsponsor.org')) {
            btn.remove();
        }
    });
    
    // Add appropriate button based on login state
    const authButton = document.createElement('a');
    authButton.className = 'nav-button mobile-auth-btn';
    
    if (isLoggedIn) {
        // Add logout button
        authButton.href = '#';
        authButton.onclick = () => { handleLogout(); return false; };
        authButton.innerHTML = '🚪 Logout';
    } else {
        // Add login button
        authButton.href = 'javascript:void(0)';
        authButton.onclick = () => {
            window.location.href = 'https://auth.directsponsor.org/jwt-login.php?redirect_uri=' + 
                encodeURIComponent(window.location.origin + window.location.pathname);
        };
        authButton.innerHTML = '🚪 Login';
    }
    
    // Append to mobile nav
    mobileNav.appendChild(authButton);
}

// One-time JWT processing - extract username then discard token
function processJwtFromUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    const jwtParam = urlParams.get('jwt');
    
    if (jwtParam) {
        console.log('🔑 Processing login token...');
        
        try {
            // Extract username from JWT token
            const payload = JSON.parse(atob(jwtParam.split('.')[1]));
            
            // DEBUG: Log the full JWT payload to see what data we have
            console.log('🔍 Full JWT Payload:', JSON.stringify(payload, null, 2));
            console.log('🔍 Email from JWT:', payload.email || 'NOT IN JWT');
            
            const username = payload.username || payload.sub || 'User';
            const userId = payload.sub || 'unknown';
            
            // Construct combined userID for direct file access performance
            const combinedUserId = `${userId}-${username}`;
            console.log(`🔧 Combined userID: ${combinedUserId}`);
            
            // Store login data in localStorage with expiration for cross-tab compatibility
            const loginData = {
                username: username,
                user_id: userId,
                combined_user_id: combinedUserId,
                login_time: Date.now(),
                expires: Date.now() + (30 * 60 * 60 * 1000) // Expire after 30 hours
            };
            localStorage.setItem('directsponsor_session', JSON.stringify(loginData));
            
            // Also set individual items for backward compatibility
            localStorage.setItem('username', username);
            localStorage.setItem('user_id', userId);
            localStorage.setItem('combined_user_id', combinedUserId);
            localStorage.setItem('login_time', Date.now().toString());
            
            // Clear old balance cache when user changes
            localStorage.removeItem('guest_transactions');
            localStorage.removeItem('last_balance_fetch');
            
            console.log(`✅ Logged in as: ${username}`);
            
            // User files are handled server-side when needed
            
            // Clean up the URL by removing JWT parameters
            const cleanUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, cleanUrl);
            
            // Update UI to show logged in state
            updateLoginButtons();
            
            // Refresh the unified balance system to detect new login status
            if (window.unifiedBalance) {
                window.unifiedBalance.refreshLoginStatus();
                // Update displays immediately
                setTimeout(() => {
                    if (window.updateBalanceDisplays) window.updateBalanceDisplays();
                    if (window.updateCurrencyDisplays) window.updateCurrencyDisplays();
                }, 100);
            }
            
            return true;
        } catch (error) {
            console.error('❌ Failed to process login token:', error);
        }
    }
    
    return false;
}

// Notification system functions
async function loadNotifications() {
    const combinedUserId = getCombinedUserId();
    if (!combinedUserId || combinedUserId === 'guest') return;
    
    try {
        const username = getValidUsername();
        const response = await fetch(`api/simple-notifications.php?action=summary&user_id=${combinedUserId}&username=${username}`);
        const data = await response.json();
        
        if (data.success) {
            updateNotificationUI(data.data.unread_count, data.data.recent);
        }
    } catch (error) {
        console.error('Failed to load notifications:', error);
    }
}

function updateNotificationUI(unreadCount, notifications) {
    // Update user menu button to show notification indicator
    const userMenuButton = document.getElementById('user-menu-button');
    const username = getValidUsername();
    
    if (userMenuButton && username) {
        if (unreadCount > 0) {
            const countText = unreadCount > 99 ? '99+' : unreadCount;
            userMenuButton.innerHTML = `👤 ${username} <span style="background: #D0021B; color: white; border-radius: 8px; padding: 1px 6px; font-size: 0.8em; margin-left: 4px;">${countText}</span>`;
        } else {
            userMenuButton.textContent = `👤 ${username}`;
        }
    }
    
    // Add inbox menu item if it doesn't exist, and update it with notification indicator
    let inboxMenuItem = document.getElementById('inbox-menu-item');
    if (!inboxMenuItem) {
        // Find the dropdown content and add inbox item
        const dropdownContent = userMenuButton?.parentElement?.querySelector('.dropdown-content');
        if (dropdownContent) {
            // Create inbox menu item
            inboxMenuItem = document.createElement('a');
            inboxMenuItem.id = 'inbox-menu-item';
            inboxMenuItem.href = 'chat.html#inbox';
            
            // Insert between Profile and Logout
            const logoutItem = dropdownContent.querySelector('a[onclick*="handleLogout"]');
            if (logoutItem) {
                dropdownContent.insertBefore(inboxMenuItem, logoutItem);
            } else {
                dropdownContent.appendChild(inboxMenuItem);
            }
        }
    }
    
    // Update inbox menu item text with notification indicator
    if (inboxMenuItem) {
        if (unreadCount > 0) {
            const countText = unreadCount > 99 ? '99+' : unreadCount;
            inboxMenuItem.innerHTML = `📥 Inbox <span style="background: #D0021B; color: white; border-radius: 8px; padding: 1px 6px; font-size: 0.8em; margin-left: 4px;">${countText}</span>`;
        } else {
            inboxMenuItem.textContent = '📥 Inbox';
        }
    }
}

function openNotifications() {
    // Open chat page with inbox tab active
    window.location.href = 'chat.html#inbox';
}

// Check for notifications periodically (every 2 minutes)
setInterval(() => {
    const combinedUserId = getCombinedUserId();
    if (combinedUserId && combinedUserId !== 'guest') {
        loadNotifications();
    }
}, 2 * 60 * 1000);

// Initialize countdown when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Process any JWT token from login redirect
    processJwtFromUrl();
    
    // Update login buttons based on current login state
    updateLoginButtons();
    
    const faucetBtn = document.getElementById('faucet-countdown-btn');
    if (faucetBtn) {
        startFaucetCountdown();
    }
});

/**
 * Update user menu button with display name if available
 * Falls back to username if no display name is set
 * @param {HTMLElement} userMenuButton - The user menu button element
 * @param {string} fallbackUsername - Username to use as fallback
 */
async function updateUserMenuDisplayName(userMenuButton, fallbackUsername) {
    try {
        const combinedUserId = getCombinedUserId();
        if (!combinedUserId) {
            userMenuButton.textContent = `👤 ${fallbackUsername}`;
            return;
        }
        
        // Try to get display name and avatar from profile
        const response = await fetch(`api/simple-profile.php?action=profile&user_id=${combinedUserId}`);
        if (response.ok) {
            const data = await response.json();
            if (data.success && data.profile) {
                const displayName = data.profile.display_name || data.profile.username || fallbackUsername;
                const avatar = data.profile.avatar || '👤';
                
                // For navigation, show camera emoji for uploaded images (too complex for nav)
                if (avatar.startsWith('uploaded:')) {
                    userMenuButton.textContent = `📷 ${displayName}`;
                } else {
                    userMenuButton.textContent = `${avatar} ${displayName}`;
                }
                return;
            }
        }
    } catch (error) {
        console.log('🔄 Could not load display name:', error.message);
    }
    
    // Fallback to username with default avatar
    userMenuButton.textContent = `👤 ${fallbackUsername}`;
}

/**
 * ARCHIVED 2025-10-05: Removed dangerous ensureUserFilesExist function
 * This function caused the critical balance data loss bug by creating
 * new balance files with 0 coins when files appeared missing.
 * 
 * For new account creation, use proper registration workflow instead
 * of silent file creation during authentication.
 */

// Charity Totals Display System
class CharityTotalsDisplay {
    constructor() {
        this.totalsData = null;
        this.updateInterval = 30000; // 30 seconds
        this.apiEndpoint = '/api/financial-totals.php';
        this.init();
    }

    async init() {
        if (this.hasCharityElements()) {
            console.log('📊 Charity Totals: Found elements, initializing...');
            await this.fetchTotals();
            this.displayTotals();
            this.setupAutoUpdate();
        }
    }

    hasCharityElements() {
        return document.querySelector('.charity-current-total, .charity-received-total, .charity-distributed-total');
    }

    async fetchTotals() {
        try {
            const response = await fetch(this.apiEndpoint);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.totalsData = await response.json();
        } catch (error) {
            console.warn('📊 Charity Totals: Could not fetch totals:', error);
            this.totalsData = null;
        }
    }

    displayTotals() {
        if (!this.totalsData || !this.totalsData.charity_fund) {
            return;
        }

        const { charity_fund } = this.totalsData;

        // Update charity fund totals
        this.updateElements('.charity-current-total', this.formatSats(charity_fund.current_total_sats));
        this.updateElements('.charity-received-total', this.formatSats(charity_fund.total_received_sats));
        this.updateElements('.charity-distributed-total', this.formatSats(charity_fund.total_distributed_sats));

        console.log('📊 Charity Totals: Updated elements with current:', charity_fund.current_total_sats, 'sats');
    }

    updateElements(selector, text) {
        const elements = document.querySelectorAll(selector);
        elements.forEach(el => {
            el.textContent = text;
            el.classList.add('charity-totals-loaded');
        });
    }

    formatSats(sats) {
        if (sats === 0) return '0 sats';
        return new Intl.NumberFormat().format(sats) + ' sats';
    }

    setupAutoUpdate() {
        setInterval(async () => {
            await this.fetchTotals();
            this.displayTotals();
        }, this.updateInterval);
    }
}

// Auto-initialize charity totals display when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new CharityTotalsDisplay();
});

// ========================================
// 🏷️ User Role System - Dynamic Loading
// ========================================

/**
 * Dynamically load role system CSS and JavaScript
 * Loads on all pages automatically via site-utils.js
 */
(function() {
    'use strict';
    
    console.log('🏷️ Role System: Initializing...');
    
    // Load role CSS
    const roleCSS = document.createElement('link');
    roleCSS.rel = 'stylesheet';
    roleCSS.href = 'css/user-roles.css';
    document.head.appendChild(roleCSS);
    
    // Load role JavaScript
    const roleJS = document.createElement('script');
    roleJS.src = 'scripts/user-roles.js';
    roleJS.onload = function() {
        console.log('✅ Role System: Loaded successfully');
    };
    roleJS.onerror = function() {
        console.warn('⚠️ Role System: Failed to load user-roles.js');
    };
    document.head.appendChild(roleJS);
    
})();
// DirectSponsor Cross-Domain Session Sync
// Checks auth server for active session and syncs locally

(async function() {
    'use strict';
    
    const AUTH_SERVER = 'https://auth.directsponsor.org';
    const SESSION_KEY = 'directsponsor_session';
    const SESSION_DURATION = 30 * 24 * 60 * 60 * 1000; // 30 days
    
    // Check if we already have a valid local session
    function hasValidLocalSession() {
        try {
            const sessionData = localStorage.getItem(SESSION_KEY);
            if (!sessionData) return false;
            
            const data = JSON.parse(sessionData);
            if (data.expires && Date.now() > data.expires) {
                return false;
            }
            
            return !!data.username;
        } catch (error) {
            return false;
        }
    }
    
    // Check auth server for session
    async function checkAuthServerSession() {
        try {
            const response = await fetch(`${AUTH_SERVER}/session-check.php`, {
                method: 'GET',
                credentials: 'include', // Important: include cookies for cross-domain
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                console.warn('Session check failed:', response.status);
                return null;
            }
            
            const data = await response.json();
            return data;
        } catch (error) {
            console.warn('Session check error:', error);
            return null;
        }
    }
    
    // Sync session from auth server to local storage
    async function syncSession() {
        // Skip if we already have a valid session
        if (hasValidLocalSession()) {
            console.log('📱 Local session valid - no sync needed');
            return;
        }
        
        console.log('🔄 Checking auth server for session...');
        
        const serverSession = await checkAuthServerSession();
        
        if (serverSession && serverSession.logged_in) {
            // Create local session from server session
            const sessionData = {
                user_id: serverSession.user_id,
                username: serverSession.username,
                combined_user_id: serverSession.combined_user_id,
                expires: Date.now() + SESSION_DURATION,
                created: Date.now(),
                synced_from_server: true
            };
            
            localStorage.setItem(SESSION_KEY, JSON.stringify(sessionData));
            
            // Also set individual items for backward compatibility
            localStorage.setItem('user_id', serverSession.user_id);
            localStorage.setItem('username', serverSession.username);
            localStorage.setItem('combined_user_id', serverSession.combined_user_id);
            
            console.log('✅ Session synced from auth server:', serverSession.username);
            
            // Reload page to show logged-in state
            window.location.reload();
        } else {
            console.log('👤 No active session on auth server');
        }
    }
    
    // Run sync on page load
    syncSession();
})();
// TEMP FIX for cache clearing
