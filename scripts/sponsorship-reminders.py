#!/usr/bin/env python3
"""
sponsorship-reminders.py
Deploy to: /root/scripts/sponsorship-reminders.py on RN1
Cron entries (add via: crontab -e):
  0 7  1 * * /usr/bin/python3 /root/scripts/sponsorship-reminders.py first  >> /var/log/ds-reminders.log 2>&1
  0 7  4 * * /usr/bin/python3 /root/scripts/sponsorship-reminders.py second >> /var/log/ds-reminders.log 2>&1
  0 7  7 * * /usr/bin/python3 /root/scripts/sponsorship-reminders.py final  >> /var/log/ds-reminders.log 2>&1
  0 8  8 * * /usr/bin/python3 /root/scripts/sponsorship-reminders.py demote >> /var/log/ds-reminders.log 2>&1

Usage:
  python3 sponsorship-reminders.py first|second|final|demote|test

test stage: prints what would be sent without sending anything.
"""

import json
import sys
import os
import urllib.request
import urllib.error
from datetime import datetime

# ── Config ──────────────────────────────────────────────────────────────────
GROUPS_DIR   = '/var/www/directsponsor.net/userdata/sponsorship-groups'
NOTIFY_URL   = 'https://auth.directsponsor.org/api/send-notification.php'
SECRET_FILE  = '/root/.ds-notify-secret'
SITE_URL     = 'https://directsponsor.net'
ADMIN_USER      = 'andy'           # DS username of admin — gets email via notify endpoint
TELEGRAM_CONFIG = '/root/.telegram-ds-authbot'  # format: TOKEN\nCHAT_ID
# ────────────────────────────────────────────────────────────────────────────

STAGE = sys.argv[1] if len(sys.argv) > 1 else 'test'
DRY_RUN = (STAGE == 'test')

now = datetime.now()
current_month = now.strftime('%Y-%m')
month_name    = now.strftime('%B %Y')

def log(msg):
    print(f'[{now.strftime("%Y-%m-%d %H:%M")}] [{STAGE}] {msg}', flush=True)

def load_secret():
    try:
        return open(SECRET_FILE).read().strip()
    except FileNotFoundError:
        log(f'ERROR: secret file not found at {SECRET_FILE}')
        sys.exit(1)

SECRET = load_secret()

def send_notification(username, subject, body_text):
    if DRY_RUN:
        log(f'[DRY RUN] Would email {username!r}: {subject!r}')
        return True
    payload = json.dumps({
        'secret':    SECRET,
        'username':  username,
        'subject':   subject,
        'body_text': body_text,
    }).encode()
    req = urllib.request.Request(
        NOTIFY_URL,
        data=payload,
        headers={'Content-Type': 'application/json'},
        method='POST'
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            resp = json.loads(r.read())
            if resp.get('success'):
                log(f'Emailed {username}: {subject}')
                return True
            else:
                log(f'ERROR emailing {username}: {resp}')
                return False
    except Exception as e:
        log(f'ERROR emailing {username}: {e}')
        return False

def telegram_alert(msg):
    if DRY_RUN:
        log(f'[DRY RUN] Telegram: {msg}')
        return
    try:
        lines = open(TELEGRAM_CONFIG).read().strip().splitlines()
        token, chat_id = lines[0].strip(), lines[1].strip()
    except Exception as e:
        log(f'Telegram config missing ({TELEGRAM_CONFIG}): {e}')
        return
    payload = json.dumps({'chat_id': chat_id, 'text': msg}).encode()
    req = urllib.request.Request(
        f'https://api.telegram.org/bot{token}/sendMessage',
        data=payload,
        headers={'Content-Type': 'application/json'},
        method='POST'
    )
    try:
        urllib.request.urlopen(req, timeout=10)
        log(f'Telegram alert sent')
    except Exception as e:
        log(f'Telegram error: {e}')

def load_group(path):
    with open(path) as f:
        return json.load(f)

def save_group(path, group):
    if DRY_RUN:
        return
    with open(path, 'w') as f:
        json.dump(group, f, indent=2)

# ── Main ─────────────────────────────────────────────────────────────────────

if not os.path.isdir(GROUPS_DIR):
    log('No groups directory found — nothing to do')
    sys.exit(0)

group_files = [
    os.path.join(GROUPS_DIR, f)
    for f in os.listdir(GROUPS_DIR)
    if f.endswith('.json') and not f.startswith('_')
]

if not group_files:
    log('No group files found — nothing to do')
    sys.exit(0)

demotions = []  # collect (recipient, sponsor_display) for Telegram summary

for path in sorted(group_files):
    group     = load_group(path)
    recipient = group.get('recipient_username', os.path.basename(path).replace('.json',''))
    members   = group.get('members', [])
    changed   = False

    for i, m in enumerate(members):
        slots = int(m.get('slots', 0))
        if slots < 1:
            continue  # skip queued members

        if m.get('last_paid_month') == current_month:
            continue  # already paid

        username = m.get('username', '')
        display  = m.get('display_name', username)
        group_url = f'{SITE_URL}/group.php?recipient={recipient}'

        # Reminder state — reset each new month
        rs = m.get('reminder_state', {})
        if rs.get('month') != current_month:
            rs = {'month': current_month}

        if STAGE == 'first' and not rs.get('r1_sent'):
            ok = send_notification(
                username,
                f'Sponsorship payment due \u2014 {month_name}',
                f'Hi {display},\n\n'
                f'This is your reminder that your monthly sponsorship payment to {recipient} '
                f'is due for {month_name}.\n\n'
                f'Pay here: {group_url}\n\n'
                f'Thank you for your support.\n'
                f'\u2014 DirectSponsor'
            )
            if ok:
                rs['r1_sent'] = now.strftime('%Y-%m-%d')
                changed = True

        elif STAGE == 'second' and not rs.get('r2_sent'):
            ok = send_notification(
                username,
                f'Reminder: sponsorship payment still due \u2014 {month_name}',
                f'Hi {display},\n\n'
                f'We noticed your payment for {month_name} to {recipient} '
                f'hasn\'t come through yet.\n\n'
                f'Pay here: {group_url}\n\n'
                f'\u2014 DirectSponsor'
            )
            if ok:
                rs['r2_sent'] = now.strftime('%Y-%m-%d')
                changed = True

        elif STAGE == 'final' and not rs.get('r3_sent'):
            ok = send_notification(
                username,
                f'Last chance \u2014 sponsorship payment due today \u2014 {month_name}',
                f'Hi {display},\n\n'
                f'Today is the last day to pay for {month_name}. '
                f'If payment is not received by midnight tonight, your slot in '
                f'{recipient}\'s group will be released to allow someone else to fill it.\n\n'
                f'This is not a penalty \u2014 it is how the system keeps income reliable '
                f'for {recipient}.\n\n'
                f'Pay here: {group_url}\n\n'
                f'\u2014 DirectSponsor'
            )
            if ok:
                rs['r3_sent'] = now.strftime('%Y-%m-%d')
                changed = True

        elif STAGE == 'demote' and not rs.get('demoted'):
            # Release the slot
            members[i]['slots'] = 0
            rs['demoted'] = True
            changed = True

            # Notify the sponsor
            send_notification(
                username,
                f'Your sponsorship slot has been released \u2014 {month_name}',
                f'Hi {display},\n\n'
                f'Your slot in {recipient}\'s sponsorship group was not paid for {month_name} '
                f'and has been released.\n\n'
                f'If you would like to rejoin when a slot is available: {group_url}\n\n'
                f'\u2014 DirectSponsor'
            )

            # Notify the recipient
            send_notification(
                recipient,
                f'Sponsorship update \u2014 slot released ({display})',
                f'Hi {recipient},\n\n'
                f'{display} did not pay for {month_name}. Their slot has been released '
                f'and is now available.\n\n'
                f'You can assign a new sponsor at: {group_url}\n\n'
                f'\u2014 DirectSponsor'
            )

            demotions.append((recipient, display))
            log(f'Demoted {username} from {recipient}\'s group')

        members[i]['reminder_state'] = rs

    if changed:
        group['members'] = members
        save_group(path, group)

# Send one Telegram summary to admin if any demotions happened
if demotions and STAGE == 'demote':
    lines = [f'\u26a0\ufe0f DS Sponsorship \u2014 slots released ({month_name}):']
    for rec, sponsor in demotions:
        lines.append(f'  \u2022 {sponsor} removed from {rec}\'s group')
    lines.append('Check the group pages to assign replacements.')
    telegram_alert('\n'.join(lines))

log(f'Done. Groups processed: {len(group_files)}. Demotions: {len(demotions)}.')
