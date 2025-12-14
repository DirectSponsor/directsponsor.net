#!/bin/bash
# Linear Include Processor
# Processes <!-- include start xxx --> and <!-- include end xxx --> tags
# Clean, fast template system for SatoshiHost network sites

echo "🔨 Building HTML files with includes..."

# Directory handling - allow building in a subdirectory (e.g., site/)
TARGET_DIR="${1:-.}"
if [[ -d "$TARGET_DIR" ]]; then
    if [[ "$TARGET_DIR" != "." ]]; then
        echo "📂 Working in directory: $TARGET_DIR"
        cd "$TARGET_DIR" || exit 1
    fi
else
    echo "❌ Directory not found: $TARGET_DIR"
    exit 1
fi

# Fix permissions first
echo "🔧 Fixing file permissions..."
# Ensure templates and includes are read-only (protected)
chmod 444 cms/templates/*.tmpl 2>/dev/null || true
chmod 444 cms/includes/*.incl 2>/dev/null || true
# Ensure HTML files are writable (for editing and building)
chmod 644 *.html 2>/dev/null || true
echo "  ✅ Permissions fixed"

# Function to process BBEdit-style parameters in includes
process_bbinclude_params() {
    local include_file="$1"
    local page_file="$2"
    
    # Start with the include file content
    local result_content=$(cat "$include_file")
    
    # Extract and process each parameter using sed
    # Look for lines like: #TITLE#="Page Title Here"
    while IFS= read -r param_line; do
        if [[ -n "$param_line" ]]; then
            # Extract parameter name and value using sed
            local param_name=$(echo "$param_line" | sed 's/^\(#[A-Z_]*#\)=.*/\1/')
            local param_value=$(echo "$param_line" | sed 's/^#[A-Z_]*#="\(.*\)"$/\1/')
            
            # Replace parameter name with value (handles both #PARAM# and #PARAM#|default)
            result_content=$(echo "$result_content" | sed "s|${param_name}\\|[^\"]*|$param_value|g")
            result_content=$(echo "$result_content" | sed "s|${param_name}|$param_value|g")
        fi
    done <<< "$(grep -o '#[A-Z_]*#="[^"]*"' "$page_file" 2>/dev/null || true)"
    
    # Process any remaining placeholders with defaults
    # Replace #PARAM#|default_value with just default_value for unused parameters
    result_content=$(echo "$result_content" | sed 's/#[A-Z_]*#|\([^"]*\)/\1/g')
    
    # Output the processed content
    echo "$result_content"
}

# Function to process includes in a file - linear single pass
process_includes() {
    local input_file="$1"
    local output_file="$2"
    local temp_file=$(mktemp)
    
    echo "📄 Processing: $input_file → $output_file"
    
    # Check if file already has DS-CMS header to avoid duplication
    if ! grep -q "DS-CMS Built File" "$input_file" 2>/dev/null; then
        # Add minimal header only if not already present
        cat > "$temp_file" << EOF
<!-- DS-CMS Built File - Edit source files in includes/ folder -->

EOF
    else
        # File already has header, start with empty temp file
        > "$temp_file"
    fi
    
    # Process file line by line
    local in_include=false
    local include_name=""
    
    while IFS= read -r line; do
        # Check for include start tag
        if [[ "$line" =~ \<!--[[:space:]]*include[[:space:]]+start[[:space:]]+([^[:space:]]+)[[:space:]]*--\> ]]; then
            include_name="${BASH_REMATCH[1]}"
            in_include=true
            echo "  📎 Including: $include_name"
            
            # Add the start comment
            echo "$line" >> "$temp_file"
            
            # Add the include file content (with parameter processing)
            include_path="cms/includes/$include_name"
            
            if [[ -f "$include_path" ]]; then
                # Process BBEdit-style parameters if they exist
                process_bbinclude_params "$include_path" "$input_file" >> "$temp_file"
            else
                echo "  ⚠️  Include file not found: $include_path"
                echo "<!-- ERROR: Include file not found: $include_path -->" >> "$temp_file"
            fi
            
        # Check for include end tag
        elif [[ "$line" =~ \<!--[[:space:]]*include[[:space:]]+end[[:space:]]+([^[:space:]]+)[[:space:]]*--\> ]]; then
            end_include_name="${BASH_REMATCH[1]}"
            
            if [[ "$end_include_name" == "$include_name" ]]; then
                # Add the end comment
                echo "$line" >> "$temp_file"
                in_include=false
                include_name=""
            else
                echo "  ⚠️  Mismatched include tags: started with '$include_name' but ended with '$end_include_name'"
                echo "$line" >> "$temp_file"
            fi
            
        # Regular line - only add if we're not inside an include block
        elif [[ "$in_include" == false ]]; then
            echo "$line" >> "$temp_file"
        fi
        # If we're inside an include block, skip the line (it gets replaced by include content)
        
    done < "$input_file"
    
    # Move final result to output
    mv "$temp_file" "$output_file"
    # Fix permissions so web server can read the file
    chmod 644 "$output_file"
    echo "  ✅ Built: $output_file"
}

# Process all HTML files with include tags
processed_count=0

# Find and process HTML files that contain include tags
for htmlfile in *.html; do
    if [[ -f "$htmlfile" ]] && grep -q "include start" "$htmlfile"; then
        # Backup disabled - using git for version control
        # cp "$htmlfile" "${htmlfile}.bak"
        # chmod 644 "${htmlfile}.bak"
        
        # Process includes in place  
        process_includes "$htmlfile" "$htmlfile"
        ((processed_count++))
    fi
done

if [[ $processed_count -eq 0 ]]; then
    echo "📁 No HTML files with includes found"
    echo "💡 Add include tags to HTML files like: \u003c!-- include start header.html --\u003e"
else
    echo ""
    echo "🎉 Build complete! Updated $processed_count HTML file(s)"
    echo ""
    echo "📝 Include syntax:"
    echo "   \u003c!-- include start header.html --\u003e"
    echo "   \u003c!-- include end header.html --\u003e"
    echo ""
    echo "📂 Directory structure:"
    echo "   cms/templates/      - Protected templates (.tmpl) - use 'Save As' to create pages"
    echo "   cms/includes/       - Protected includes (.incl) - shared HTML snippets"
    echo "   *.html              - Your pages (edit these directly)"
fi

# Ensure script exits with success
exit 0

