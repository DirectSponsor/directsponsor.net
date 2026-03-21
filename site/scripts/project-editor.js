// Import existing auth system
console.log('🚀 PROJECT-EDITOR.JS FILE LOADED AND EXECUTING!');

class ProjectEditor {
    constructor() {
        console.log('🎨 ProjectEditor: Constructor called');
        this.projectId = null;
        this.projectData = null;
        this.currentUser = null;
        this.init();
    }
    
    async init() {
        // Get project ID and action from URL
        const params = new URLSearchParams(window.location.search);
        const action = params.get('action');
        this.projectId = params.get('project') || null;
        
        // Check authentication using inline function
        this.currentUser = this.getValidUsername();
        if (!this.currentUser) {
            window.location.href = 'profile.html';
            return;
        }
        
        // Check if creating new project
        if (action === 'create') {
            console.log('🆕 Create mode detected');
            // Wait for role system to load
            await this.waitForRoleSystem();
            
            // Check recipient permission
            if (!window.isRecipient || !window.isRecipient()) {
                console.log('❌ Not a recipient, access denied');
                document.getElementById('accessDenied').style.display = 'block';
                document.getElementById('accessDenied').innerHTML = `
                    <h1>⚠️ Recipient Role Required</h1>
                    <p>You need the Recipient role to create fundraising projects.</p>
                    <p>Please contact an administrator to request the Recipient role.</p>
                    <br>
                    <a href="profile.html" class="btn btn-secondary">← Back to Profile</a>
                `;
                return;
            }
            
            console.log('✅ Recipient role verified');
            // Show empty form for new project
            this.projectData = {};
            this.setupFormForCreate();
            this.setupFormHandlers();
            document.getElementById('editForm').style.display = 'block';
        } else {
            // Edit existing project
            this.projectId = this.projectId || '001';
            await this.loadProjectData();
        }
    }
    
    async waitForRoleSystem() {
        return new Promise((resolve) => {
            if (window.userRoles) {
                resolve();
            } else {
                setTimeout(resolve, 1000);
            }
        });
    }
    
    setupFormForCreate() {
        document.getElementById('projectSubtitle').textContent = 'Create a new fundraising project';
        // Leave all fields empty - they're already empty by default
        console.log('🆕 Create mode: Form ready for new project');
    }
    
    // Inline auth function (from site-utils.js)
    getValidUsername() {
        try {
            const sessionData = localStorage.getItem('directsponsor_session');
            if (sessionData) {
                const data = JSON.parse(sessionData);
                // Check if session has expired
                if (data.expires && Date.now() > data.expires) {
                    console.log('Session expired, logging out automatically');
                    // Clear expired session
                    localStorage.removeItem('directsponsor_session');
                    localStorage.removeItem('username');
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
    
    async loadProjectData() {
        try {
            console.log('🔍 Loading project data for:', this.projectId, 'user:', this.currentUser);
            
            const response = await fetch('/api/project-management-api-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_project',
                    project_id: this.projectId,
                    username: this.currentUser
                })
            });
            
            console.log('📡 API Response status:', response.status);
            const result = await response.json();
            console.log('📋 API Result:', result);
            console.log('🔍 Success?', result.success);
            console.log('❌ Error?', result.error);
            console.log('📊 Data?', result.data);
            
            if (result.success) {
                this.projectData = result.data;
                this.populateForm();
                this.setupFormHandlers();
                document.getElementById('editForm').style.display = 'block';
            } else {
                if (result.error === 'Access denied') {
                    document.getElementById('accessDenied').style.display = 'block';
                } else {
                    this.showStatus('❌ ' + (result.error || 'Failed to load project'), 'error');
                }
            }
        } catch (error) {
            console.error('Failed to load project:', error);
            this.showStatus('❌ Failed to load project data', 'error');
        }
    }
    
    populateForm() {
        // Update page title
        document.getElementById('projectSubtitle').textContent = `Editing: ${this.projectData.title}`;
        
        // Populate form fields
        document.getElementById('projectTitle').value = this.projectData.title || '';
        document.getElementById('shortDescription').value = this.projectData.description || '';
        document.getElementById('targetAmount').value = this.projectData.target_amount || '';
        document.getElementById('recipientName').value = this.projectData.recipient_name || '';
        document.getElementById('location').value = this.projectData.location || '';
        
        document.getElementById('websiteUrl').value = this.projectData.website_url || '';
        
        // Update current target display
        document.getElementById('currentTarget').textContent = (this.projectData.target_amount || 0).toLocaleString();
        
        // Handle contenteditable full description
        const fullDescDiv = document.getElementById('fullDescription');
        if (this.projectData.full_description) {
            // Convert plain text back to HTML for contenteditable
            const paragraphs = this.projectData.full_description.split('\n\n');
            const htmlContent = paragraphs
                .filter(p => p.trim())
                .map(p => `<p>${p.trim()}</p>`)
                .join('');
            fullDescDiv.innerHTML = htmlContent || '<p></p>';
        } else {
            fullDescDiv.innerHTML = '<p></p>';
        }
        
        // Handle current image display
        const currentImage = document.getElementById('currentImage');
        const currentImg = document.getElementById('currentImg');
        if (this.projectData.main_image) {
            currentImg.src = this.projectData.main_image;
            currentImage.style.display = 'block';
        } else {
            currentImage.style.display = 'none';
        }
    }
    
    setupFormHandlers() {
        document.getElementById('projectEditForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveChanges();
        });
        
        // Preview buttons removed - unified system doesn't need them
        // Users can save and access project directly from profile page
        
        // Set up image upload handlers
        this.setupImageHandlers();
    }
    
    setupImageHandlers() {
        const fileInput = document.getElementById('projectImage');
        const imagePreview = document.getElementById('imagePreview');
        const previewImg = document.getElementById('previewImg');
        const currentImage = document.getElementById('currentImage');
        
        // Handle new image selection
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    this.showStatus('❌ Image file too large (max 5MB)', 'error');
                    fileInput.value = '';
                    return;
                }
                
                // Preview the image
                const reader = new FileReader();
                reader.onload = (e) => {
                    previewImg.src = e.target.result;
                    imagePreview.style.display = 'block';
                    currentImage.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    async saveChanges() {
        try {
            this.showStatus('💾 Saving changes...', 'info');
            console.log('DEBUG: Starting save process');
            
            // For new projects, we need to save the project first to get an ID,
            // then upload the image. Store the file for later.
            const fileInput = document.getElementById('projectImage');
            const hasNewImage = fileInput.files.length > 0;
            const imageFile = hasNewImage ? fileInput.files[0] : null;
            
            // Get form data (without image for now)
            const formData = {
                action: this.projectId ? 'update_project' : 'create_project',
                project_id: this.projectId,
                username: this.currentUser,
                title: document.getElementById('projectTitle').value,
                description: document.getElementById('shortDescription').value,
                full_description: document.getElementById('fullDescription').innerHTML,
                target_amount: parseInt(document.getElementById('targetAmount').value),
                recipient_name: document.getElementById('recipientName').value,
                location: document.getElementById('location').value,
                website_url: document.getElementById('websiteUrl').value,
                main_image: this.projectData.main_image || '' // Keep existing or empty for now
            };
            
            console.log('DEBUG: Form data:', formData);
            
            // Save the project first
            const response = await fetch('/api/project-management-api-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            
            console.log('DEBUG: Response status:', response.status);
            const result = await response.json();
            console.log('DEBUG: API response:', result);
            
            if (!result.success) {
                this.showStatus('❌ ' + (result.error || 'Failed to save changes'), 'error');
                return;
            }
            
            // If this was a new project, get the generated ID
            if (!this.projectId && result.project_id) {
                this.projectId = result.project_id;
                console.log('DEBUG: New project created with ID:', this.projectId);
            }
            
            // Now upload the image if there is one
            if (hasNewImage && imageFile) {
                console.log('DEBUG: Uploading image for project ID:', this.projectId);
                const imageUrl = await this.uploadImage(imageFile);
                if (imageUrl) {
                    // Update the project with the image URL
                    const updateImageData = {
                        action: 'update_project',
                        project_id: this.projectId,
                        username: this.currentUser,
                        main_image: imageUrl
                    };
                    await fetch('/api/project-management-api-v2.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(updateImageData)
                    });
                }
            }
            
            // Show success message - redirect to profile to see project
            this.showStatus(`✅ Project saved successfully! <a href="profile.html" style="color: #007bff; text-decoration: underline;">👁️ Back to Profile</a>`, 'success');
            
            // Auto-redirect to profile after 2 seconds
            setTimeout(() => {
                window.location.href = 'profile.html';
            }, 2000);
        } catch (error) {
            console.error('Save failed:', error);
            this.showStatus('❌ Network error - please try again.', 'error');
        }
    }
    
    async uploadImage(file) {
        try {
            const formData = new FormData();
            formData.append('image', file);
            formData.append('project_id', this.projectId);
            formData.append('username', this.currentUser);
            
            const response = await fetch('/api/upload-project-image.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            console.log('📷 DEBUG: Upload API response:', result);
            
            if (result.success) {
                console.log('📷 DEBUG: Returning image URL:', result.image_url);
                return result.image_url;
            } else {
                console.error('Image upload failed:', result.error);
                return null;
            }
        } catch (error) {
            console.error('Image upload error:', error);
            return null;
        }
    }
    
    showStatus(message, type) {
        const statusEl = document.getElementById('statusMessage');
        statusEl.innerHTML = message + '<button class="status-close" onclick="this.parentElement.style.display=\'none\'">×</button>';
        statusEl.className = `status-message status-${type}`;
        statusEl.style.display = 'block';
        
        // Don't auto-hide success messages so the "View Project" link stays visible
        // Users can manually dismiss if needed
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    console.log('🔧 Edit page: DOMContentLoaded fired, initializing ProjectEditor...');
    try {
        new ProjectEditor();
        console.log('✅ Edit page: ProjectEditor initialized successfully');
    } catch (error) {
        console.error('❌ Edit page: Failed to initialize ProjectEditor:', error);
    }
});

console.log('📝 Edit page script loaded');
