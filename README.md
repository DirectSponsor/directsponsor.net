# DirectSponsor.net - Main Charity Platform

## 🎯 Purpose
The primary DirectSponsor platform - a charity website built with DS-CMS that showcases peer-to-peer sponsorship of verified projects. Acts as a bridge between a normal website log-in and the nostr network enabling existing nostr users to join as well as those who have never heard of nostr.

## 🏗️ Structure
```
net-site/
├── core/                    # Main DS-CMS working directory
│   ├── includes/           # Reusable HTML components
│   ├── styles/             # CSS files
│   ├── images/             # Site images (to be created)
│   ├── evans-content/      # Scraped Evans project content
│   ├── Namibia-content/    # Grant & Annegret project content
│   ├── *.html              # Page source files
│   ├── build.sh            # Build script
│   └── deploy-*.sh         # Deployment scripts
└── README.md               # This file
```

## 🌐 Live Site
- **URL**: https://directsponsor.net
- **Status**: ✅ Live and functional
- **Hosting**: DirectAdmin shared hosting
- **SSL**: Active and properly configured

## 📸 Image Optimization
- **Smart optimization**: Automatic resizing and compression for uploads
- **See**: [IMAGE_OPTIMIZATION.md](IMAGE_OPTIMIZATION.md) for details

## 🛠️ Development Workflow

### Making Changes
```bash
cd /home/andy/Documents/websites/Warp/projects/directsponsor/net-site/core

# Edit HTML files as needed
# Build the site
./build.sh

# Deploy to production
./deploy-safe.sh --auto
```

### Key Features
- **BBEdit-style includes**: Modular template system
- **Automated deployment**: One-command deployment
- **Permission management**: Auto-fixes file permissions
- **Backup system**: Automatic backups during builds

## 📋 Current Pages
- ✅ **index.html** - Homepage with hero section
- ✅ **projects.html** - Evans' and Grant & Annegret's projects
- ✅ **about.html** - Mission and approach
- ✅ **contact.html** - Contact form
- ✅ **how-it-works.html** - Process explanation

## 🎯 Current Status (June 23, 2025)

### ✅ Completed
- Basic site structure and navigation
- Template system working perfectly
- Deployment automation
- Permission issues resolved
- HTML validation and clean output

### 🔄 In Progress
- Visual improvements and imagery
- Content organization and enhancement
- Project photo integration

### 📋 Next Priorities
1. **Images directory structure** - Organize project photos
2. **Visual enhancements** - Hero sections, project thumbnails
3. **Content polish** - Better copy and presentation
4. **Advanced features** - Progress tracking, galleries

## 🔧 Technical Notes

### Build System
- Uses DS-CMS template processing
- Automatically handles include files
- Sets proper file permissions (644)
- Creates backup files (.bak)

### Deployment
- SSH-based deployment to DirectAdmin
- Automatic permission fixing
- File compression and optimization
- Safety verification before deployment

### File Permissions
- **HTML/CSS files**: 644 (-rw-r--r--)
- **Directories**: 755 (drwxr-xr-x)
- **Scripts**: 755 (executable)

## 🎨 Design System

### Current Theme
- Clean, professional appearance
- Blue/white color scheme
- Mobile-responsive design
- Fast loading, minimal dependencies

### Components
- Header with dropdown navigation
- Project cards with verification badges
- Hero sections on each page
- Footer with links and branding

---

**Working Directory**: `/home/andy/Documents/websites/Warp/projects/directsponsor/net-site/core`
**Last Updated**: June 23, 2025
**Status**: Active development, live site functional
