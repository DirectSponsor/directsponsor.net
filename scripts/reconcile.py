#!/usr/bin/env python3
"""
reconcile.py — DirectSponsor donation reconciliation report

Cross-checks three sources of truth:
  1. transaction-ledger.json  — authoritative record of confirmed payments
  2. Per-user donations_made  — written to profile files by webhook
  3. Project HTML files       — current-amount + recent_donations blocks

Run on RN1:
  python3 /root/scripts/reconcile.py

Or from local machine:
  ssh RN1 "python3 /root/scripts/reconcile.py"
"""

import json
import glob
import os
import re
from collections import defaultdict

USERDATA    = '/var/www/directsponsor.net/userdata'
LEDGER_FILE = os.path.join(USERDATA, 'data/transaction-ledger.json')
PROFILES_DIR = os.path.join(USERDATA, 'profiles')
PROJECTS_DIR = os.path.join(USERDATA, 'projects')
WEBHOOK_LOG  = os.path.join(USERDATA, 'logs/webhook.log')


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def load_json(path):
    try:
        with open(path) as f:
            return json.load(f)
    except FileNotFoundError:
        return None
    except json.JSONDecodeError as e:
        print(f"  [WARN] JSON parse error in {path}: {e}")
        return None


def ledger_key(entry):
    """Stable key to match a ledger entry to a donations_made entry."""
    return (
        entry.get('timestamp', ''),
        entry.get('project_id', ''),
        str(entry.get('amount_sats', '')),
    )


def profile_key(entry):
    """Same fields from a donations_made entry."""
    return (
        entry.get('timestamp', ''),
        entry.get('project_id', ''),
        str(entry.get('amount_sats', '')),
    )


def find_profile_file(username):
    matches = glob.glob(os.path.join(PROFILES_DIR, f'*-{username}.txt'))
    return matches[0] if matches else None


def parse_html_amount(html, tag):
    m = re.search(rf'<!-- {tag} -->([^<]+)<!-- end {tag} -->', html)
    if m:
        return int(m.group(1).replace(',', '').strip())
    return None


def count_recent_donations(html):
    m = re.search(r'<!-- recent_donations -->(.*?)<!-- end recent_donations -->', html, re.DOTALL)
    if not m:
        return None
    return m.group(1).count('<li>')


def section(title):
    print()
    print('=' * 70)
    print(f'  {title}')
    print('=' * 70)


# ---------------------------------------------------------------------------
# Load ledger
# ---------------------------------------------------------------------------

section('Loading transaction ledger')

raw = load_json(LEDGER_FILE)
if raw is None:
    print(f"  ERROR: ledger file not found or unreadable: {LEDGER_FILE}")
    raise SystemExit(1)

all_transactions = raw.get('transactions', [])
project_txns = [t for t in all_transactions if t.get('type') == 'project_donation']

print(f"  Total ledger entries : {len(all_transactions)}")
print(f"  Project donations    : {len(project_txns)}")

# Group by donor_username (skip anonymous — no profile to check)
# NOTE: Historical entries (pre 2026-04-08 webhook fix) have a known data bug:
# donor_username was incorrectly set to the RECIPIENT's username instead of the
# actual donor's username. Entries where donor_username == recipient_username are
# flagged as "suspect" rather than reported as genuine discrepancies.
by_donor = defaultdict(list)
by_donor_suspect = defaultdict(list)  # likely corrupted (donor == recipient)
anon_count = 0
for t in project_txns:
    du = t.get('donor_username') or ''
    ru = t.get('recipient_username') or ''
    if du:
        if du == ru:
            by_donor_suspect[du].append(t)
        else:
            by_donor[du].append(t)
    else:
        anon_count += 1

print(f"  Anonymous (no username)          : {anon_count}  — skipped (no profile to check)")
print(f"  Named donors (reliable)          : {sum(len(v) for v in by_donor.values())} entries across {len(by_donor)} users")
print(f"  Suspect entries (donor==recipient): {sum(len(v) for v in by_donor_suspect.values())}  — likely pre-fix ledger corruption")

# Group by recipient+project for HTML checks
by_project = defaultdict(list)
for t in project_txns:
    key = (t.get('recipient_username', ''), t.get('project_id', ''))
    by_project[key].append(t)


# ---------------------------------------------------------------------------
# Check 1: Ledger entries missing from donor profiles (donations_made)
# ---------------------------------------------------------------------------

section('CHECK 1: Ledger entries missing from donor profiles')

print("  Note: entries where donor_username == recipient_username are checked separately")
print("  as they likely have corrupted donor data from a pre-2026-04-08 webhook bug.")
print()

missing_from_profile = []
suspect_entries = []

for username, txns in sorted(by_donor.items()):
    profile_path = find_profile_file(username)

    if profile_path is None:
        for t in txns:
            missing_from_profile.append({
                'username': username,
                'entry': t,
                'reason': 'profile file not found on disk',
            })
        continue

    profile_data = load_json(profile_path)
    if profile_data is None:
        for t in txns:
            missing_from_profile.append({
                'username': username,
                'entry': t,
                'reason': 'profile file unreadable/invalid JSON',
            })
        continue

    profile_donations = profile_data.get('donations_made', [])
    profile_keys = [profile_key(d) for d in profile_donations]

    for t in txns:
        k = ledger_key(t)
        if k not in profile_keys:
            missing_from_profile.append({
                'username': username,
                'entry': t,
                'reason': 'entry not found in donations_made array',
            })

# Also collect suspect entries (donor==recipient) for separate reporting
for username, txns in sorted(by_donor_suspect.items()):
    profile_path = find_profile_file(username)
    profile_data = load_json(profile_path) if profile_path else None
    profile_donations = (profile_data or {}).get('donations_made', [])
    profile_keys = [profile_key(d) for d in profile_donations]
    for t in txns:
        k = ledger_key(t)
        suspect_entries.append({
            'username': username,
            'entry': t,
            'in_profile': k in profile_keys,
        })

if not missing_from_profile:
    print("  OK — all reliable ledger entries found in donor profiles.")
else:
    print(f"  DISCREPANCIES FOUND: {len(missing_from_profile)}\n")
    for d in missing_from_profile:
        t = d['entry']
        print(f"  Donor    : {d['username']}")
        print(f"  Project  : {t.get('project_id')}  Recipient: {t.get('recipient_username')}")
        print(f"  Amount   : {t.get('amount_sats')} sats")
        print(f"  Timestamp: {t.get('timestamp')}")
        print(f"  Reason   : {d['reason']}")
        print(f"  Diagnosis: Check webhook.log around {t.get('timestamp')} for payment_hash {t.get('payment_hash', 'N/A')[:20]}...")
        print(f"             Likely cause: profile file missing or glob-pattern bug (pre-fix donations)")
        print()

# Report suspect entries
print(f"  Suspect entries (donor==recipient, pre-fix ledger corruption): {len(suspect_entries)}")
suspect_missing = [e for e in suspect_entries if not e['in_profile']]
if suspect_missing:
    print(f"  Of these, {len(suspect_missing)} are also absent from the profile (could be self-donations lost to glob bug):")
    for e in suspect_missing:
        t = e['entry']
        print(f"    {t.get('timestamp')}  {t.get('amount_sats')} sats  project={t.get('project_id')}  user={e['username']}")
else:
    print(f"  All suspect entries are present in the user's own profile (self-donations recorded OK).")


# ---------------------------------------------------------------------------
# Check 2: Profile donations_made entries missing from ledger
# ---------------------------------------------------------------------------

section('CHECK 2: Profile donations_made entries missing from ledger')

ledger_keys_set = set(ledger_key(t) for t in project_txns)
missing_from_ledger = []

all_profiles = glob.glob(os.path.join(PROFILES_DIR, '*.txt'))
for ppath in sorted(all_profiles):
    pdata = load_json(ppath)
    if pdata is None:
        continue
    username = pdata.get('username', os.path.basename(ppath))
    for d in pdata.get('donations_made', []):
        k = profile_key(d)
        if k not in ledger_keys_set:
            missing_from_ledger.append({
                'username': username,
                'entry': d,
                'profile': ppath,
            })

if not missing_from_ledger:
    print("  OK — all profile donations_made entries found in ledger.")
else:
    print(f"  DISCREPANCIES FOUND: {len(missing_from_ledger)}\n")
    for d in missing_from_ledger:
        e = d['entry']
        print(f"  Donor    : {d['username']}")
        print(f"  Project  : {e.get('project_id')}  Recipient: {e.get('recipient')}")
        print(f"  Amount   : {e.get('amount_sats')} sats")
        print(f"  Timestamp: {e.get('timestamp')}")
        print(f"  Diagnosis: Profile write may have succeeded but ledger write failed.")
        print(f"             Check webhook.log around {e.get('timestamp')}")
        print()


# ---------------------------------------------------------------------------
# Check 3: Project HTML current-amount vs ledger sum
# ---------------------------------------------------------------------------

section('CHECK 3: Project HTML current-amount vs ledger sum')

html_issues = []

for (recipient, project_id), txns in sorted(by_project.items()):
    if not recipient or not project_id:
        continue

    ledger_sum = sum(t.get('amount_sats', 0) for t in txns)

    # Look in both active/ and completed/
    html_path = None
    for subdir in ('active', 'completed'):
        candidate = os.path.join(PROJECTS_DIR, recipient, subdir, f'{project_id}.html')
        if os.path.exists(candidate):
            html_path = candidate
            break

    if html_path is None:
        html_issues.append({
            'recipient': recipient, 'project_id': project_id,
            'issue': 'HTML file not found on disk',
            'ledger_sum': ledger_sum, 'html_amount': None,
        })
        continue

    with open(html_path) as f:
        html = f.read()

    html_amount = parse_html_amount(html, 'current-amount')
    donation_count_in_html = count_recent_donations(html)

    if html_amount is None:
        html_issues.append({
            'recipient': recipient, 'project_id': project_id,
            'issue': 'current-amount tag missing from HTML',
            'ledger_sum': ledger_sum, 'html_amount': None,
        })
        continue

    if html_amount != ledger_sum:
        delta = html_amount - ledger_sum
        html_issues.append({
            'recipient': recipient, 'project_id': project_id,
            'issue': f'current-amount ({html_amount:,}) != ledger sum ({ledger_sum:,})  delta={delta:+,} sats',
            'ledger_sum': ledger_sum, 'html_amount': html_amount,
            'donation_count_html': donation_count_in_html,
            'donation_count_ledger': len(txns),
        })

if not html_issues:
    print("  OK — all project HTML amounts match ledger sums.")
else:
    print(f"  DISCREPANCIES FOUND: {len(html_issues)}\n")
    for h in html_issues:
        print(f"  Recipient : {h['recipient']}  Project: {h['project_id']}")
        print(f"  Issue     : {h['issue']}")
        if 'donation_count_html' in h:
            print(f"  HTML <li> count  : {h.get('donation_count_html')}")
            print(f"  Ledger txn count : {h.get('donation_count_ledger')}")
        print(f"  Diagnosis: Possible manual HTML edit, or a payment that bypassed the ledger.")
        print(f"             Cross-check with webhook.log for full payment history.")
        print()


# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

section('SUMMARY')

total_issues = len(missing_from_profile) + len(missing_from_ledger) + len(html_issues)
if total_issues == 0:
    print("  All checks passed. No discrepancies found.")
else:
    print(f"  Missing from donor profiles : {len(missing_from_profile)}")
    print(f"  Missing from ledger         : {len(missing_from_ledger)}")
    print(f"  HTML amount mismatches      : {len(html_issues)}")
    print()
    print(f"  To investigate: grep webhook.log for the relevant timestamps or payment hashes.")
    print(f"  Webhook log location: {WEBHOOK_LOG}")
    print(f"")
    print(f"  NOTE: The webhook ledger bug (donor_username stored recipient instead of donor)")
    print(f"  was fixed on 2026-04-08. Historical entries are flagged as 'suspect' above.")

print()
