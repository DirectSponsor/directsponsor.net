#!/bin/bash
# add-changelog.sh — Prepend a changelog entry to site/changelog.html
# Usage: ./add-changelog.sh "Category" "Description of what changed."
# Example: ./add-changelog.sh "Feature" "Sponsorship groups now show payment history."

SITE_NAME="DirectSponsor"
CHANGELOG="site/changelog.html"

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 \"Category\" \"Description\""
    echo "Example: $0 \"Feature\" \"Sponsorship groups now show payment history.\""
    exit 1
fi

CATEGORY="$1"
DESC="$2"
DATE=$(date +%Y-%m-%d)

export SITE_NAME CATEGORY DESC DATE CHANGELOG
python3 - <<'PYEOF'
import os, sys
site     = os.environ['SITE_NAME']
category = os.environ['CATEGORY']
desc     = os.environ['DESC']
date     = os.environ['DATE']
changelog = os.environ['CHANGELOG']

with open(changelog, 'r') as f:
    content = f.read()

new_li = ('            <li><strong>' + date + '</strong> · <strong>' + site +
          '</strong> — <span class="feature">' + category + '</span> ' + desc + '</li>')

marker = '<!-- EMBED:changelog -->\n        <ul>'
if marker not in content:
    print('ERROR: Could not find <!-- EMBED:changelog --> block in ' + changelog)
    sys.exit(1)

content = content.replace(marker, marker + '\n' + new_li, 1)

with open(changelog, 'w') as f:
    f.write(content)

print('✅ Changelog updated: ' + date + ' · ' + site + ' — [' + category + '] ' + desc)
PYEOF
