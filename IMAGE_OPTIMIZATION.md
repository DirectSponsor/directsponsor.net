# Image Optimization System

## Overview

DirectSponsor.net includes a smart image optimization system that intelligently handles user uploads without making already-optimized images worse.

## Quick Start

```bash
# Optimize all images in the images directory
./optimize-images.sh site/images

# Optimize a specific project
./optimize-images.sh site/images/projects/evans

# Show help
./optimize-images.sh --help
```

## How It Works

The optimization script:
- ✅ **Only optimizes when needed** - Checks file size (>500KB) and dimensions (>1920px) first
- ✅ **Never makes images worse** - Compares before/after, keeps the better version
- ✅ **JPEG quality 60** - Perfect balance for web (imperceptible quality loss, huge file size savings)
- ✅ **Resizes large images** - Max 1920px width (catches phone uploads at 3000-4000px)
- ✅ **Strips EXIF data** - Removes metadata for privacy and smaller files
- ✅ **Detailed feedback** - Shows what was optimized and how much was saved

## When to Use

### For User Uploads (Phone Photos)

When users upload photos from their phones (typically 3000-4000px and 2-5MB):

```bash
./optimize-images.sh site/images
```

The script will automatically:
1. Detect oversized images
2. Resize to 1920px max width
3. Compress to JPEG quality 60
4. Show how much space was saved

### For Hand-Optimized Images

If you've already optimized images manually:
- The script will detect they're already good and skip them
- No re-compression or quality loss
- Your work is preserved

## Example Output

```
🖼️  DirectSponsor Image Optimizer

📂 Processing directory: site/images

⚙️  Optimizing: phone-upload-4032x3024.jpg
   Original: 4032x3024 (3.2MB)
✅ Saved 2.8MB (87%)
   New: 1920x1440 (412KB)

✓ Already optimized: hero.jpg (245KB)
✓ Good dimensions: logo.png (1200x630)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ Complete!
   Total images: 3
   Optimized: 1
   Total saved: 2.8MB
```

## Optimization Criteria

**Will optimize if:**
- File size > 500KB AND width > 1920px
- OR file size > 500KB (even if dimensions are OK)

**Will skip if:**
- File already < 500KB (already optimized)
- Width already ≤ 1920px (good dimensions)
- Optimization would make file larger (original is better)

## Technical Details

### Settings
- **Max width**: 1920px (perfect for modern displays)
- **JPEG quality**: 60 (imperceptible loss, huge savings)
- **Min file size**: 500KB (skip already-small files)

### Requirements
- **ImageMagick v6 or v7** (`convert`/`identify` or `magick` commands)
- The script auto-detects which version is installed
- Install on Debian/Ubuntu: `sudo apt-get install imagemagick`

**Note**: When open-sourcing, document in requirements as:
```
Requirements:
- ImageMagick v6 or v7
- Bash 4.0+
```

## Image Directory Structure

```
site/images/
├── projects/
│   ├── evans/              # Evans' food forest photos
│   └── grant-annegret/     # Grant & Annegret's desert farm photos
├── branding/               # Logo, favicon, social cards
└── ui/                     # Hero backgrounds, patterns
```

See [site/images/README.md](site/images/README.md) for detailed image guidelines.

## Files

- **[optimize-images.sh](optimize-images.sh)** - The optimization script
- **[site/images/README.md](site/images/README.md)** - Image directory documentation and guidelines

## Best Practices

1. **Upload originals** - Don't pre-optimize, let the script handle it
2. **Run after uploads** - Process new images before deploying
3. **Check the output** - Review what was optimized and savings achieved
4. **Keep originals** - The script modifies in-place, so commit before optimizing if you want backups

---

**Created**: 2026-01-18  
**Status**: Production ready
