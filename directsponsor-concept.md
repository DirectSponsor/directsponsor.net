# DirectSponsor Content Management

## The Beauty of Simple File-Based Content

Perfect for DirectSponsor because:

### 1. **Project Pages** (like Evans, Annegret)
- Each project gets its own HTML file
- Rich content: images, progress updates, funding stats
- Easy to version control and backup
- No database to corrupt or maintain

### 2. **Social Media Posts/Updates**
- Each post is a simple HTML file with metadata
- Chronologically organized in folders: `/posts/2025/01/update-123.html`
- Easy to aggregate into feeds
- Can be syndicated to Nostr later

### 3. **Blog Articles**
- Long-form content with rich formatting
- SEO-friendly (real HTML files)
- Fast loading (no database queries)
- Easy to migrate or backup

### 4. **Static Pages** (About, How It Works, etc.)
- Simple HTML files
- Easy to edit with WYSIWYG editor
- Version controlled with Git

## Example File Structure:

```
directsponsor.net/
├── index.html
├── about.html
├── how-it-works.html
├── projects/
│   ├── evans-food-forest.html
│   ├── annegret-desert-farm.html
│   └── project-template.tmpl
├── blog/
│   ├── 2025/
│   │   ├── 01/
│   │   │   ├── charity-problem.html
│   │   │   └── peer-to-peer-revolution.html
│   │   └── 02/
│   └── index.html (auto-generated list)
├── posts/
│   ├── 2025/
│   │   ├── 01/
│   │   │   ├── evans-planted-trees.html
│   │   │   └── new-verification.html
│   └── feed.json (auto-generated)
└── cms/
    ├── admin/
    │   ├── editor.html
    │   └── file-writer.php
    └── templates/
        ├── project.tmpl
        ├── blog-post.tmpl
        └── social-update.tmpl
```

## Content Types & Templates:

### Project Page Template:
```html
<!-- #TITLE#="Evans - Food Forest" #TYPE#="project" #STATUS#="active" -->
<div class="project-header">
    <h1>{{project_name}}</h1>
    <div class="funding-bar">$150 of $600/month funded</div>
</div>
<div class="project-content">
    <!-- Rich content goes here -->
</div>
```

### Social Update Template:
```html
<!-- #TITLE#="Just planted 500 seedlings" #AUTHOR#="Evans" #TIMESTAMP#="2025-01-02T14:30:00Z" -->
<div class="social-update">
    <div class="author">Evans</div>
    <div class="timestamp">2h</div>
    <div class="content">Just planted 500 more seedlings! 🌱</div>
</div>
```

## Why This Works for DirectSponsor:

1. **Zero Dependencies** - Just HTML files, no database
2. **Maximum Simplicity** - Edit with WYSIWYG, save as file
3. **Perfect for Shared Hosting** - No special server requirements
4. **Version Control** - Git tracks every change
5. **Backup-Friendly** - Just copy files
6. **Fast** - No database queries, just serve static files
7. **SEO-Friendly** - Real HTML pages
8. **Future-Proof** - HTML will always work

## Nostr Integration Later:
- Each post/update gets a unique ID
- Metadata includes Nostr event data
- Can be syndicated to Nostr network
- But content always lives in files first

## No Real-Time Collaboration Needed:
- Content creators work on drafts
- Admin approves and publishes
- No conflicts, no merge nightmares
- Simple workflow: Edit → Save → Publish
```
