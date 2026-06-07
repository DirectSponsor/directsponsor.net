#!/usr/bin/env python3
"""
restore-sponsor.py
Re-adds a sponsor to a group as if freshly joined and paid for the current month.
Useful after a demotion (missed payment test) or to re-add a lapsed sponsor.

Deploy to: /root/scripts/restore-sponsor.py on RN1

Usage:
  python3 restore-sponsor.py <recipient> <username> [slots]

  recipient  — recipient username (e.g. kelvin)
  username   — sponsor username to restore (e.g. andytest2)
  slots      — number of slots to restore (default: restores previous slot count,
                or 1 if no prior record exists)

Examples:
  python3 restore-sponsor.py kelvin andytest2
  python3 restore-sponsor.py evans andytest2 2
"""

import json
import sys
import os
from datetime import datetime

GROUPS_DIR = '/var/www/directsponsor.net/userdata/sponsorship-groups'

if len(sys.argv) < 3:
    print(__doc__)
    sys.exit(1)

recipient = sys.argv[1].lower()
username  = sys.argv[2].lower()
slots_arg = int(sys.argv[3]) if len(sys.argv) > 3 else None

now           = datetime.now()
current_month = now.strftime('%Y-%m')

group_file = os.path.join(GROUPS_DIR, recipient + '.json')
if not os.path.exists(group_file):
    print(f'ERROR: group file not found: {group_file}')
    sys.exit(1)

with open(group_file) as f:
    group = json.load(f)

members = group.get('members', [])
found   = False

for m in members:
    if m.get('username') != username:
        continue
    found = True

    # Determine slots to restore
    slots = slots_arg or max(int(m.get('slots', 0)), 1)

    old_slots = m.get('slots', 0)
    old_state = m.get('reminder_state', {})

    m['slots']           = slots
    m['last_paid_month'] = current_month
    m['reminder_state']  = {}

    print(f'Restored {username} in {recipient}\'s group:')
    print(f'  slots:           {old_slots} → {slots}')
    print(f'  last_paid_month: set to {current_month}')
    print(f'  reminder_state:  {old_state} → cleared')
    break

if not found:
    # Sponsor was fully removed or never existed — add them fresh
    slots = slots_arg or 1
    members.append({
        'username':        username,
        'display_name':    username,
        'slots':           slots,
        'joined_date':     now.strftime('%Y-%m-%d'),
        'note':            '',
        'payments':        [],
        'last_paid':       now.strftime('%Y-%m-%d'),
        'last_paid_month': current_month,
        'reminder_state':  {}
    })
    group['members'] = members
    print(f'Added {username} as new sponsor in {recipient}\'s group ({slots} slot(s))')

group['members'] = members

tmp = group_file + '.tmp'
with open(tmp, 'w') as f:
    json.dump(group, f, indent=2)
os.replace(tmp, group_file)

print(f'Saved: {group_file}')
print(f'Done. {username} is now active in {recipient}\'s group for {current_month}.')
