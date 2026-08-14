---
description: add a changelog entry and deploy (DirectSponsor)
---

Run this at the end of any session where significant work was done.

**What counts as significant**: new features, user-visible bug fixes, security changes, system config changes. Skip typo fixes, minor refactors, WIP.

1. From the repo root, run:
```bash
./add-changelog.sh "Category" "One-line description for non-technical readers."
```
Common categories: `Feature`, `Bug Fix`, `Security`, `UI`, `Auth`, `API`, `Deployment`

2. Deploy so the live changelog is updated:
```bash
bash /home/andy/work/projects/directsponsor.net/deploy.sh
```
