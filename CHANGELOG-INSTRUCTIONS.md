# DirectSponsor Changelog Update Instructions

**IMPORTANT**: After completing **significant work** on DirectSponsor, update the public changelog.

---

## Location
- **Changelog File**: `/home/andy/work/projects/directsponsor.net/site/changelog.html`
- **Instructions**: This file (CHANGELOG-INSTRUCTIONS.md)

## When to Update

Update the changelog when you complete **significant work** such as:

✅ **DO UPDATE for:**
- New features or UI changes
- Bug fixes with user-visible impact
- System/architecture changes
- Donation flow or webhook changes
- Auth or role management changes
- Config or deployment changes
- Breaking changes (flag clearly)

❌ **SKIP for:**
- Typo fixes
- Minor code refactors
- Work-in-progress
- Style tweaks
- Internal optimizations with no user impact

---

## How to Update

### Format

Prepend a new entry **at the top** of the `<!-- EMBED:changelog -->` block:

```html
<!-- EMBED:changelog -->
<ul>
  <li><strong>YYYY-MM-DD</strong> · <strong>DirectSponsor</strong> — <span class="feature">Category</span> What changed and why it matters (keep to one line)</li>
  ... existing entries ...
</ul>
<!-- /EMBED:changelog -->
```

### Step-by-Step

1. Open `/home/andy/work/projects/directsponsor.net/site/changelog.html`
2. Find the `<!-- EMBED:changelog -->` block
3. Add your entry **inside the `<ul>` block, at the very top** (before existing entries)
4. Use today's date in `YYYY-MM-DD` format
5. Keep the entry to a single line
6. Write for non-technical readers (what changed, not how)
7. **Deploy** — run the deploy script so the live site is updated:
   ```bash
   bash /home/andy/work/projects/directsponsor.net/deploy.sh --auto
   ```

### Example Entry

```html
<li><strong>2026-04-11</strong> · <strong>DirectSponsor</strong> — <span class="feature">Donation Flow</span> Webhook now automatically advances the fundraiser queue when a goal is reached, with no manual steps needed.</li>
```

---

## Categories (Optional)

Use these categories to label entries for clarity:

- `<span class="feature">Feature</span>` — New functionality
- `<span class="feature">Donation Flow</span>` — Donation or payment changes
- `<span class="feature">Auth</span>` — Authentication or role changes
- `<span class="feature">Bug Fix</span>` — Bug fixes
- `<span class="feature">Performance</span>` — Performance improvements
- `<span class="feature">Security</span>` — Security updates
- `<span class="feature">Deployment</span>` — Deployment/ops changes

Or just use plain text if you prefer.

---

## Rules

1. **One entry per session/task** — not one entry per file changed
2. **Most recent at top** — new entries always go first
3. **Never remove old entries** — keep the full history
4. **One line only** — keep it concise
5. **Non-technical language** — write for users, not developers
6. **Pagination** — if the list exceeds 50 entries, paginate (show 50 per page, most recent first). Not needed yet but worth implementing before the page gets unwieldy.

---

## Aggregation (Meta-Changelog)

The `<!-- EMBED:changelog -->` / `<!-- /EMBED:changelog -->` comment tags exist so a future script can aggregate changelogs from all sites into one combined feed.

**How it works:**
- Each site has `changelog.html` with an `<!-- EMBED:changelog -->` block
- Each `<li>` entry identifies its source via `<strong>SiteName</strong>` (e.g. `DirectSponsor`, `ROFLFaucet`)
- A meta-changelog script can:
  1. Fetch the `changelog.html` from each site
  2. Extract the content between `<!-- EMBED:changelog -->` and `<!-- /EMBED:changelog -->`
  3. Merge all `<li>` entries and sort by date (the `YYYY-MM-DD` in the first `<strong>` tag)
  4. Render them on a central "All Updates" page

**Sites using this system:**
- `https://roflfaucet.com/changelog.html` — ROFLFaucet
- `https://directsponsor.net/changelog.html` — DirectSponsor
- `https://clickforcharity.net/changelog.html` — ClickForCharity

**To add more sites:** just ensure their `changelog.html` uses the same `<!-- EMBED:changelog -->` block and `<strong>YYYY-MM-DD</strong> · <strong>SiteName</strong>` entry format.

**Meta-changelog design notes (for when this gets built):**
- Navigation: show link buttons at the top of the page (one per site) rather than linking the site name inline on every entry — fewer links, less visual noise
- Pagination: show 50 entries per page to avoid loading a huge list on a single page

---

## AI Agent Reminder

After you complete significant work, **please update the changelog**. It helps the user track what's been done and makes the project more maintainable. It only takes 30 seconds!

The format is simple, and examples are above. Just prepend one line to the top of the changelog list.

✨ **Thank you!**
