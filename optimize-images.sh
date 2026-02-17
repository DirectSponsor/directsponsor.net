#!/bin/bash
# Smart Image Optimization for DirectSponsor.net
# Only optimizes images when needed - never makes them worse!
# Perfect for catching phone uploads while preserving hand-optimized images

set -e

# Configuration
MAX_WIDTH=1920          # Resize images wider than this
JPEG_QUALITY=60         # Sweet spot for web - imperceptible loss, huge savings
MIN_SIZE_KB=500         # Only optimize files larger than this (in KB)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check for required tools
check_dependencies() {
    local missing=()
    
    # Check for ImageMagick v7 (preferred) or v6 (fallback)
    if command -v magick &> /dev/null; then
        # ImageMagick v7 - use 'magick' command
        CONVERT_CMD="magick convert"
        IDENTIFY_CMD="magick identify"
    elif command -v convert &> /dev/null; then
        # ImageMagick v6 - use legacy commands
        CONVERT_CMD="convert"
        IDENTIFY_CMD="identify"
    else
        missing+=("imagemagick")
    fi
    
    if [ ${#missing[@]} -gt 0 ]; then
        echo -e "${RED}❌ Missing required tools: ${missing[*]}${NC}"
        echo "Install with: sudo apt-get install imagemagick"
        echo "Requires: ImageMagick v6 or v7"
        exit 1
    fi
}

# Get file size in bytes
get_file_size() {
    local file="$1"
    # Try Linux stat first, fall back to macOS stat
    stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null
}

# Get image width
get_image_width() {
    local file="$1"
    $IDENTIFY_CMD -format "%w" "$file" 2>/dev/null || echo "0"
}

# Get image height
get_image_height() {
    local file="$1"
    $IDENTIFY_CMD -format "%h" "$file" 2>/dev/null || echo "0"
}

# Format bytes to human readable
format_bytes() {
    local bytes=$1
    if [ $bytes -lt 1024 ]; then
        echo "${bytes}B"
    elif [ $bytes -lt 1048576 ]; then
        echo "$((bytes / 1024))KB"
    else
        echo "$((bytes / 1048576))MB"
    fi
}

# Optimize a single image
optimize_image() {
    local file="$1"
    local filename=$(basename "$file")
    
    # Check if file exists and is readable
    if [ ! -f "$file" ] || [ ! -r "$file" ]; then
        echo -e "${RED}❌ Cannot read: $filename${NC}"
        return 1
    fi
    
    # Get original file info
    local original_size=$(get_file_size "$file")
    local original_width=$(get_image_width "$file")
    local original_height=$(get_image_height "$file")
    
    # Check if we got valid dimensions
    if [ "$original_width" = "0" ] || [ "$original_height" = "0" ]; then
        echo -e "${YELLOW}⚠️  Skipping (not a valid image): $filename${NC}"
        return 0
    fi
    
    local original_size_kb=$((original_size / 1024))
    
    # Skip if already small enough
    if [ $original_size_kb -lt $MIN_SIZE_KB ]; then
        echo -e "${GREEN}✓${NC} Already optimized: $filename ($(format_bytes $original_size))"
        return 0
    fi
    
    # Skip if dimensions are already reasonable
    if [ $original_width -le $MAX_WIDTH ]; then
        echo -e "${GREEN}✓${NC} Good dimensions: $filename (${original_width}x${original_height})"
        return 0
    fi
    
    # Create temporary file for optimized version
    local temp_file="${file}.optimizing.tmp"
    
    echo -e "${BLUE}⚙️  Optimizing: $filename${NC}"
    echo "   Original: ${original_width}x${original_height} ($(format_bytes $original_size))"
    
    # Optimize: resize to max width, set quality
    # -resize '1920>' means: resize to 1920px width only if larger (> means "shrink only")
    # -quality 60 for JPEG compression
    # -strip removes EXIF data (privacy + smaller file)
    if $CONVERT_CMD "$file" \
        -resize "${MAX_WIDTH}>" \
        -quality $JPEG_QUALITY \
        -strip \
        "$temp_file" 2>/dev/null; then
        
        # Get new file info
        local new_size=$(get_file_size "$temp_file")
        local new_width=$(get_image_width "$temp_file")
        local new_height=$(get_image_height "$temp_file")
        
        # Only replace if actually smaller
        if [ $new_size -lt $original_size ]; then
            local saved_bytes=$((original_size - new_size))
            local saved_percent=$(( (saved_bytes * 100) / original_size ))
            
            # Replace original with optimized version
            mv "$temp_file" "$file"
            
            echo -e "${GREEN}✅ Saved $(format_bytes $saved_bytes) ($saved_percent%)${NC}"
            echo "   New: ${new_width}x${new_height} ($(format_bytes $new_size))"
            return 0
        else
            # Original was better, keep it
            rm -f "$temp_file"
            echo -e "${GREEN}✓${NC} Original was better, keeping it"
            return 0
        fi
    else
        # Optimization failed
        rm -f "$temp_file"
        echo -e "${RED}❌ Failed to optimize: $filename${NC}"
        return 1
    fi
}

# Process directory recursively
process_directory() {
    local dir="$1"
    local total=0
    local optimized=0
    local saved_total=0
    
    echo -e "${BLUE}📂 Processing directory: $dir${NC}"
    echo ""
    
    # Find all image files (jpg, jpeg, png)
    while IFS= read -r -d '' file; do
        ((total++))
        
        local before_size=$(get_file_size "$file")
        
        if optimize_image "$file"; then
            local after_size=$(get_file_size "$file")
            if [ $after_size -lt $before_size ]; then
                ((optimized++))
                saved_total=$((saved_total + before_size - after_size))
            fi
        fi
        
        echo ""
    done < <(find "$dir" -type f \( -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" \) -print0)
    
    # Summary
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}✅ Complete!${NC}"
    echo "   Total images: $total"
    echo "   Optimized: $optimized"
    if [ $saved_total -gt 0 ]; then
        echo "   Total saved: $(format_bytes $saved_total)"
    fi
}

# Main script
main() {
    echo -e "${BLUE}🖼️  DirectSponsor Image Optimizer${NC}"
    echo ""
    
    # Check dependencies
    check_dependencies
    
    # Get target directory
    local target_dir="${1:-.}"
    
    if [ ! -d "$target_dir" ]; then
        echo -e "${RED}❌ Directory not found: $target_dir${NC}"
        exit 1
    fi
    
    # Process directory
    process_directory "$target_dir"
}

# Show usage if --help
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    echo "DirectSponsor Image Optimizer"
    echo ""
    echo "Usage: $0 [directory]"
    echo ""
    echo "Optimizes images in the specified directory (default: current directory)"
    echo ""
    echo "Features:"
    echo "  • Only optimizes when needed (file size > ${MIN_SIZE_KB}KB)"
    echo "  • Never makes images worse (compares before/after)"
    echo "  • Resizes large images to max ${MAX_WIDTH}px width"
    echo "  • JPEG quality ${JPEG_QUALITY} (perfect for web)"
    echo "  • Strips EXIF data for privacy and smaller files"
    echo ""
    echo "Examples:"
    echo "  $0                    # Optimize current directory"
    echo "  $0 site/images        # Optimize site/images directory"
    echo "  $0 site/images/projects/evans  # Optimize specific project"
    exit 0
fi

# Run main function
main "$@"
