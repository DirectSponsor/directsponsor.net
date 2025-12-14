#!/bin/bash

# DS-CMS Safe Deploy Script for DirectSponsor.net
# Enhanced with domain verification to prevent accidental cross-domain deployment
# WARNING: This deploys to .NET - NOT .ORG!

set -e  # Exit on any error

# Check for --auto flag
AUTO_MODE=false
if [ "$1" = "--auto" ]; then
    AUTO_MODE=true
    echo "🤖 AUTO MODE: Skipping interactive confirmations (ORG DOMAIN)"
fi

# =============================================================================
# CONFIGURATION - UPDATE THESE FOR YOUR HOSTING
# =============================================================================

# Domain-specific configuration
TARGET_DOMAIN="directsponsor.net"
# REMOTE_HOST="directadmin-de.kxe.io"  # OLD
# REMOTE_USER="directsponsor"  # OLD
# REMOTE_PORT="10500"  # OLD
REMOTE_HOST="RN1" # Alias for 104.168.38.197
REMOTE_USER="root"
REMOTE_PORT="22"
REMOTE_PATH="/var/www/directsponsor.net/html/"

# SSH Configuration (optional - use if you have SSH config aliases)
SSH_CONFIG_ALIAS="RN1"
SSH_KEY_PATH="~/.ssh/id_rsa"  # Default key or update if specific

# Deploy method
DEPLOY_METHOD="scp"  # Options: "rsync", "scp", or "manual"

# =============================================================================
# COLOR CODES
# =============================================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
PURPLE='\033[0;35m'
ORANGE='\033[0;33m'
NC='\033[0m' # No Color

# =============================================================================
# SAFETY FUNCTIONS
# =============================================================================

show_banner() {
    echo -e "${ORANGE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${ORANGE}║               DS-CMS Safe Deployment Script                    ║${NC}"
    echo -e "${ORANGE}║                   DirectSponsor.NET                            ║${NC}"
    echo -e "${ORANGE}║                  ⚠️  NOT .ORG! ⚠️                              ║${NC}"
    echo -e "${ORANGE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo
}

verify_target() {
    echo -e "${BLUE}🔍 DEPLOYMENT TARGET VERIFICATION${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}Target Domain:${NC} ${TARGET_DOMAIN}"
    echo -e "${YELLOW}Remote Host:${NC}   ${REMOTE_HOST}"
    echo -e "${YELLOW}Remote User:${NC}   ${REMOTE_USER}"
    echo -e "${YELLOW}Remote Port:${NC}   ${REMOTE_PORT}"
    echo -e "${YELLOW}Remote Path:${NC}   ${REMOTE_PATH}"
    if [ -n "$SSH_CONFIG_ALIAS" ]; then
        echo -e "${YELLOW}SSH Alias:${NC}     ${SSH_CONFIG_ALIAS}"
    fi
    echo
    echo -e "${RED}⚠️  CRITICAL SAFETY CHECK ⚠️${NC}"
    echo -e "${RED}This will deploy to: ${TARGET_DOMAIN}${NC}"
    echo -e "${RED}Remote directory: ${REMOTE_PATH}${NC}"
    echo -e "${ORANGE}THIS IS THE .NET DOMAIN - NOT .ORG!${NC}"
    echo
    
    echo -e "${YELLOW}Proceeding in 3 seconds... (Ctrl+C to cancel)${NC}"
    sleep 1
    echo -e "${YELLOW}2...${NC}"
    sleep 1
    echo -e "${YELLOW}1...${NC}"
    sleep 1
    
    echo -e "${GREEN}✅ Proceeding with deployment to: ${TARGET_DOMAIN}${NC}"
    echo
}

git_operations() {
    echo -e "${BLUE}🐙 Checking Git status...${NC}"
    
    # Check for uncommitted changes
    if [ -n "$(git status --porcelain)" ]; then
        echo -e "${YELLOW}📝 Uncommitted changes detected.${NC}"
        
        if [ "$AUTO_MODE" = "true" ]; then
             echo -e "${GREEN}🤖 AUTO MODE: Committing changes automatically...${NC}"
             git add .
             git commit -m "Auto-deploy update: $(date)"
             
             echo -e "${BLUE}⬆️  Pushing to remote...${NC}"
             git push
        else
            echo -e "${YELLOW}Do you want to commit and push these changes? (y/N)${NC}"
            read -r commit_choice
            if [[ "$commit_choice" =~ ^[Yy]$ ]]; then
                echo -e "${BLUE}Enter commit message (Press Enter for default):${NC}"
                read -r commit_msg
                if [ -z "$commit_msg" ]; then
                    commit_msg="Auto-deploy update: $(date)"
                fi
                
                git add .
                git commit -m "$commit_msg"
                
                echo -e "${BLUE}⬆️  Pushing to remote...${NC}"
                git push
            else
                echo -e "${YELLOW}⚠️  Skipping git commit/push.${NC}"
            fi
        fi
    else
        echo -e "${GREEN}✅ No uncommitted changes.${NC}"
        
        if git status -sb | grep -q 'ahead'; then
             echo -e "${YELLOW}⚠️  Local branch is ahead of remote.${NC}"
             if [ "$AUTO_MODE" = "true" ]; then
                echo -e "${GREEN}🤖 AUTO MODE: Pushing to remote...${NC}"
                git push
             else
                echo -e "${YELLOW}Do you want to push changes to remote? (y/N)${NC}"
                read -r push_choice
                if [[ "$push_choice" =~ ^[Yy]$ ]]; then
                    echo -e "${BLUE}⬆️  Pushing to remote...${NC}"
                    git push
                fi
             fi
        fi
    fi
    echo
}

test_ssh_connection() {
    echo -e "${BLUE}🔐 Testing SSH connection...${NC}"
    
    if [ -n "$SSH_CONFIG_ALIAS" ]; then
        SSH_TARGET="$SSH_CONFIG_ALIAS"
    else
        SSH_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
    fi
    
    # Test SSH connection with timeout
    if timeout 10 ssh -i "$SSH_KEY_PATH" -p "$REMOTE_PORT" -o ConnectTimeout=5 -o BatchMode=yes "$SSH_TARGET" "echo 'SSH connection successful'" 2>/dev/null; then
        echo -e "${GREEN}✅ SSH connection successful${NC}"
        
        # Verify remote directory exists
        if ssh -i "$SSH_KEY_PATH" -p "$REMOTE_PORT" "$SSH_TARGET" "[ -d '$REMOTE_PATH' ]" 2>/dev/null; then
            echo -e "${GREEN}✅ Remote directory exists: $REMOTE_PATH${NC}"
        else
            echo -e "${YELLOW}⚠️  Remote directory does not exist: $REMOTE_PATH${NC}"
            echo -e "${YELLOW}Attempting to create it...${NC}"
            if ssh -i "$SSH_KEY_PATH" -p "$REMOTE_PORT" "$SSH_TARGET" "mkdir -p '$REMOTE_PATH'" 2>/dev/null; then
                echo -e "${GREEN}✅ Remote directory created${NC}"
            else
                echo -e "${RED}❌ Failed to create remote directory${NC}"
                exit 1
            fi
        fi
    else
        echo -e "${RED}❌ SSH connection failed${NC}"
        echo -e "${YELLOW}💡 Troubleshooting tips:${NC}"
        echo "   • Check that SSH key is uploaded to DirectAdmin"
        echo "   • Verify hostname and username are correct"
        echo "   • Confirm SSH port (usually 22 for DirectAdmin)"
        echo "   • Try manual SSH: ssh -p $REMOTE_PORT $SSH_TARGET"
        exit 1
    fi
    echo
}

dry_run_preview() {
    echo -e "${BLUE}🔍 DRY RUN PREVIEW${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    
    if [ -n "$SSH_CONFIG_ALIAS" ]; then
        SSH_TARGET="$SSH_CONFIG_ALIAS"
    else
        SSH_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
    fi
    
    echo -e "${YELLOW}SCP command that will be executed:${NC}"
    echo "scp -i $SSH_KEY_PATH -P $REMOTE_PORT -r $TEMP_DIR/* $SSH_TARGET:$REMOTE_PATH"
    echo
    
    # Show what files will be transferred
    echo -e "${YELLOW}Files that will be transferred:${NC}"
    find "$TEMP_DIR" -type f | head -15
    if [ "$(find "$TEMP_DIR" -type f | wc -l)" -gt 15 ]; then
        echo "   ... and more files"
    fi
    echo
    
    echo -e "${ORANGE}⚠️  FINAL WARNING: This deploys to ${TARGET_DOMAIN} (.NET)${NC}"
    if [ "$AUTO_MODE" = "true" ]; then
        echo -e "${GREEN}🤖 AUTO MODE: Automatically proceeding with .NET deployment${NC}"
    else
        echo -e "${YELLOW}Proceed with actual deployment? (y/N):${NC}"
        read -r proceed
        
        if [[ ! "$proceed" =~ ^[Yy]$ ]]; then
            echo -e "${YELLOW}❌ Deployment cancelled${NC}"
            exit 0
        fi
    fi
}

# =============================================================================
# MAIN DEPLOYMENT SCRIPT
# =============================================================================

main() {
    show_banner
    
    echo -e "${BLUE}📂 Current directory: $(pwd)${NC}"
    echo -e "${BLUE}🕐 Started at: $(date)${NC}"
    echo
    
    # Safety verification
    verify_target
    
    # Step 0: Git Operations
    git_operations
    
    # Step 1: Build the site
    echo -e "${BLUE}🔨 Building site with DS-CMS...${NC}"
    if [ -f "./build.sh" ]; then
        ./build.sh
        echo -e "${GREEN}✅ Build completed${NC}"
    else
        echo -e "${YELLOW}⚠️  build.sh not found, skipping build step${NC}"
    fi
    echo
    
    # Step 2: Prepare deployment files
    echo -e "${BLUE}📦 Preparing deployment files...${NC}"
    TEMP_DIR=$(mktemp -d)
    echo -e "${BLUE}📁 Temp directory: $TEMP_DIR${NC}"
    
    # Copy only production files
    rsync -av \
        --include='*.html' \
        --include='styles/' \
        --include='styles/**' \
        --include='js/' \
        --include='js/**' \
        --include='images/' \
        --include='images/**' \
        --include='assets/' \
        --include='assets/**' \
        --include='favicon.ico' \
        --include='robots.txt' \
        --include='.htaccess' \
        --exclude='includes/' \
        --exclude='build.sh' \
        --exclude='deploy*.sh' \
        --exclude='*.template.html' \
        --exclude='*.bak' \
        --exclude='*.md' \
        --exclude='Namibia-content/' \
        --exclude='evans-content/' \
        --exclude='.*' \
        ./ "$TEMP_DIR/"
    
    echo -e "${GREEN}✅ Production files prepared${NC}"
    
    # Show file count
    FILE_COUNT=$(find "$TEMP_DIR" -type f | wc -l)
    echo -e "${BLUE}📊 Files to deploy: $FILE_COUNT${NC}"
    echo -e "${BLUE}📋 Sample files:${NC}"
    find "$TEMP_DIR" -type f | head -10
    if [ "$FILE_COUNT" -gt 10 ]; then
        echo "   ... and $((FILE_COUNT - 10)) more files"
    fi
    echo
    
    # Step 3: Test SSH connection
    if [ "$DEPLOY_METHOD" = "scp" ]; then
        test_ssh_connection
        
        # Step 4: Deployment preview
        dry_run_preview
        
        # Step 5: Actual deployment
        echo -e "${BLUE}🚀 Deploying to ${TARGET_DOMAIN}...${NC}"
        
        if [ -n "$SSH_CONFIG_ALIAS" ]; then
            SSH_TARGET="$SSH_CONFIG_ALIAS"
        else
            SSH_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
        fi
        
        # Deploy with SCP
        echo -e "${BLUE}📡 Transferring files via SCP...${NC}"
        scp -i "$SSH_KEY_PATH" -P "$REMOTE_PORT" -r "$TEMP_DIR"/* "$SSH_TARGET:$REMOTE_PATH"
        
        echo -e "${GREEN}✅ Deployment completed successfully!${NC}"
        
    elif [ "$DEPLOY_METHOD" = "rsync" ]; then
        echo -e "${BLUE}📁 SCP deployment method${NC}"
        echo -e "${YELLOW}📝 SCP command to run:${NC}"
        echo "scp -P $REMOTE_PORT -r $TEMP_DIR/* $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"
        echo
        echo -e "${YELLOW}Press Enter when ready to execute SCP...${NC}"
        read -r
        scp -P "$REMOTE_PORT" -r "$TEMP_DIR"/* "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"
        echo -e "${GREEN}✅ SCP deployment completed!${NC}"
        
    else
        echo -e "${BLUE}📁 Manual deployment prepared${NC}"
        echo -e "${YELLOW}📝 Files ready in: $TEMP_DIR${NC}"
        echo -e "${YELLOW}📋 Manual deployment options:${NC}"
        echo "   • DirectAdmin File Manager: Upload contents to $REMOTE_PATH"
        echo "   • FTP Client: Upload to $REMOTE_PATH"
        echo "   • Zip and extract: Create archive, upload via DirectAdmin"
        echo
        echo -e "${YELLOW}Press Enter when manual deployment is complete...${NC}"
        read -r
    fi
    
    # Cleanup
    echo -e "${BLUE}🧹 Cleaning up temporary files...${NC}"
    rm -rf "$TEMP_DIR"
    
    # Success message
    echo
    echo -e "${GREEN}🎉 DEPLOYMENT COMPLETE! 🎉${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}✅ Site deployed to: ${TARGET_DOMAIN}${NC}"
    echo -e "${GREEN}✅ Remote path: ${REMOTE_PATH}${NC}"
    echo -e "${GREEN}✅ Completed at: $(date)${NC}"
    echo
    
    # Post-deployment checklist
    echo -e "${YELLOW}📋 POST-DEPLOYMENT CHECKLIST:${NC}"
    echo "   □ Test the live site: https://${TARGET_DOMAIN}"
    echo "   □ Verify navigation menu and dropdown work"
    echo "   □ Check that all project pages load correctly"
    echo "   □ Test contact form functionality"
    echo "   □ Verify SSL certificate is working"
    echo "   □ Check mobile responsiveness"
    echo
    echo -e "${BLUE}🌐 Visit your site: https://${TARGET_DOMAIN}${NC}"
}

# =============================================================================
# SCRIPT EXECUTION
# =============================================================================

# Check if script is being run directly
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi
