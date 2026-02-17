# DirectSponsor Image Directory Structure

This directory contains all images for the DirectSponsor.net site.

## Directory Organization

```
images/
├── projects/           # Project-specific photos
│   ├── evans/         # Evans' food forest project
│   │   ├── hero.jpg   # Main project hero image
│   │   ├── *.jpg      # Progress photos, activities, etc.
│   │   └── README.md  # Photo credits and descriptions
│   └── grant-annegret/ # Grant & Annegret's desert farm
│       ├── hero.jpg   # Main project hero image
│       ├── *.jpg      # Farm photos, activities, etc.
│       └── README.md  # Photo credits and descriptions
├── branding/          # Logo, favicon, social cards
│   ├── logo.svg       # Main logo (SVG for scalability)
│   ├── logo-dark.svg  # Dark mode logo
│   ├── favicon.ico    # Browser favicon
│   └── og-image.jpg   # Open Graph social sharing image
└── ui/                # UI elements, backgrounds, patterns
    ├── hero-bg.jpg    # Homepage hero background
    └── patterns/      # Background patterns, textures
```

## Image Guidelines

### For Project Photos
- **Format**: JPEG for photos, PNG for graphics with transparency
- **Size**: Upload originals - the optimization script will handle resizing
- **Max width**: Images will be auto-resized to 1920px max width
- **Quality**: Auto-optimized to JPEG quality 60 (perfect for web)
- **Naming**: Use descriptive names: `seedlings-planting-2026-01.jpg`

### For Branding
- **Logo**: SVG preferred (scalable), or high-res PNG
- **Favicon**: 32x32 or 64x64 ICO file
- **OG Image**: 1200x630px for social media sharing

### Optimization
All images are automatically optimized when you run:
```bash
./optimize-images.sh site/images
```

The script:
- ✅ Only optimizes images that need it (>500KB, >1920px width)
- ✅ Never makes images worse (compares before/after)
- ✅ Preserves hand-optimized images
- ✅ Perfect for catching phone uploads

## Adding New Images

1. **Upload your images** to the appropriate directory
2. **Run optimization** (optional, but recommended for phone photos):
   ```bash
   ./optimize-images.sh site/images
   ```
3. **Reference in HTML**:
   ```html
   <img src="images/projects/evans/hero.jpg" alt="Evans' food forest">
   ```

## Credits

Always document photo credits in the project README.md files.
