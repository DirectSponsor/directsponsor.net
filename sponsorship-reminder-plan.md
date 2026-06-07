# Sponsorship Reminder System — Design Plan

**Status**: Planned — not yet built  
**Depends on**: `sponsorship-api.php`, `webhook.php`, auth server email infrastructure

---

## Goal

Send monthly email reminders to active sponsors who haven't paid, track the response window,
and automatically demote non-responsive members. All via email — every registered user has an
email address on the auth server; no Telegram or other channel required.

---

## How the Payment Window Works

✅ **Decided: 7-day grace window**

- **Days 1–7** of month M: sponsor pays for month M
- **Day 8 onwards**: sponsor can only pay for month M+1

This means "missed month M" is confirmed on day 8. A sponsor who misses the window cannot
retroactively pay for that month.

Rationale: recipients may be in serious poverty and depend on this income. The commitment
must be treated as essential, not optional. 7 days is enough for a sponsor to log in and
pay; anything longer delays finding a replacement. Demotion on day 8 leaves ~3 weeks of
the month for a standby to be promoted before the next payment cycle.

**Code change required**: `sponsorship-api.php` grace window check — change `5` to `7`.

---

## Email Infrastructure

User emails live in the auth server's SQLite database (`users.email`). The auth server already
sends mail for magic login and verification, so the mail config is working.

**Approach**: add a lightweight `send-notification.php` endpoint on the auth server that DS
cron scripts can call with a shared secret. The endpoint looks up the user's email from the
DB and sends it. This keeps email ownership on the auth server and avoids duplicating email
addresses in DS profile files.

```
POST https://auth.directsponsor.org/api/send-notification.php
{
  "secret": "<shared secret stored in /root/.ds-notify-secret on RN1>",
  "username": "john",
  "subject": "Sponsorship reminder — May 2026",
  "body_text": "Plain text version",
  "body_html": "HTML version (optional)"
}
```

Response: `{"success": true}` or `{"error": "user not found"}` etc.

---

## Reminder Schedule

Three reminder points per month, plus one demotion pass:

✅ **Decided schedule** (built around 7-day grace window):

| Day of month | Action |
|---|---|
| Day 1 | **First reminder** — all unpaid active sponsors |
| Day 4 | **Second reminder** — still unpaid |
| Day 7 | **Final warning** — last day of grace window; "pay today or your slot is released tomorrow" |
| Day 8 | **Demotion pass** — demote non-payers immediately; promote standbys; notify recipient |

Running demotion on day 8 leaves ~3 weeks for the recipient to confirm the promoted standby
or recruit a new one before the next cycle.

---

## State Tracking

Reminder state is stored per member in the group JSON file alongside existing fields:

```json
{
  "username": "john",
  "slots": 1,
  "last_paid": "2026-05-03",
  "last_paid_month": "2026-05",
  "reminder_state": {
    "month": "2026-05",
    "r1_sent": "2026-05-01",
    "r2_sent": "2026-05-08",
    "r3_sent": "2026-05-14",
    "demoted": false
  }
}
```

The `month` field prevents re-sending reminders when the cron runs multiple times per day.
State resets each new month (just check `reminder_state.month !== currentMonth`).

---

## What the Emails Say

### First reminder (day 1)
> **Subject**: Sponsorship payment due — May 2026
>
> Hi [display_name],
>
> This is a reminder that your monthly sponsorship payment to [recipient display name] is due
> for May 2026.
>
> Your commitment: [slots] slot(s) at $[slots×10]/month.
>
> Pay here: https://directsponsor.net/group.php?recipient=[recipient]
>
> Thank you for your support.
> — DirectSponsor

### Second reminder (day 8)
> **Subject**: Reminder: sponsorship payment still due — May 2026
>
> Same as above with "We noticed your payment hasn't come through yet."

### Final warning (day 14)
> **Subject**: Last chance — sponsorship payment May 2026
>
> Hi [display_name],
>
> Your sponsorship payment for May 2026 is still outstanding. If payment is not received by
> [date], your slot will be released to a waiting sponsor.
>
> This is not a penalty — it's how the system keeps income reliable for [recipient].
>
> Pay here: https://directsponsor.net/group.php?recipient=[recipient]

### Demotion notification to sponsor (day 16)
> **Subject**: Your sponsorship slot has been released — May 2026
>
> Hi [display_name],
>
> Your slot in [recipient]'s sponsorship group was not paid for May 2026 and has been
> released. You have been moved to the waiting list.
>
> If you'd like to rejoin, visit: https://directsponsor.net/group.php?recipient=[recipient]

### Recipient notification (day 16, if demotion occurred)
> **Subject**: Sponsorship group update — [sponsor] slot released
>
> Hi [recipient],
>
> [sponsor display name] did not pay for May 2026. Their slot has been released and
> [standby name / "the waiting list"] has been notified.
>
> View your group: https://directsponsor.net/group.php?recipient=[recipient]

---

## Files to Create / Modify

| File | Action |
|------|--------|
| `/root/scripts/sponsorship-reminders.sh` on RN1 | **Create** — main cron script |
| `auth-server/auth/website/api/send-notification.php` | **Create** — email dispatch endpoint |
| `site/api/sponsorship-api.php` | **Minor edit** — extend grace window (if decided) |
| Group JSON files | **Auto-updated** — reminder state fields added by cron |

### Cron entries on RN1

```cron
0 7 1 * * /root/scripts/sponsorship-reminders.sh first   >> /var/log/ds-reminders.log 2>&1
0 7 8 * * /root/scripts/sponsorship-reminders.sh second  >> /var/log/ds-reminders.log 2>&1
0 7 14 * * /root/scripts/sponsorship-reminders.sh final  >> /var/log/ds-reminders.log 2>&1
0 8 16 * * /root/scripts/sponsorship-reminders.sh demote >> /var/log/ds-reminders.log 2>&1
```

The script takes the reminder stage as argument so we can test each stage independently.

### Script logic outline (`sponsorship-reminders.sh`)

```bash
STAGE=$1   # first | second | final | demote
MONTH=$(date +%Y-%m)
GROUPS_DIR=/var/www/directsponsor.net/userdata/sponsorship-groups

for group_file in $GROUPS_DIR/[^_]*.json; do
    # Parse recipient, members with slots > 0
    # For each unpaid member (last_paid_month != MONTH):
    #   Check reminder_state.month == MONTH to avoid duplicates
    #   Depending on STAGE:
    #     first/second/final → POST to auth server send-notification.php
    #     demote → update slots=0 in group file, POST demotion emails
    #   Update reminder_state in group file
done
```

Use `python3` or `jq` for JSON parsing (both available on RN1 — prefer python3 for consistency
with other RN1 scripts).

---

## Decided Questions

1. ✅ **Grace window**: 7 days (days 1–7 pay for current month; day 8+ pays for next month).
2. ✅ **Miss policy**: 1 missed month = immediate demotion. No consecutive-miss leniency.
   Recipients depend on this income — sponsors must understand it is a serious commitment,
   not casual support. This is structural, not punitive.
3. ✅ **When a slot opens**: cron notifies the admin (email) that a slot is available. The
   recipient finds a replacement through their own network — no formal queue or standby list.
4. **Shared secret storage** — keep in `/root/.ds-notify-secret` on RN1 and in a config on the
   auth server. Do not commit to git.

## Slot Model — Full or Available

A recipient's group is simply **full** or **has openings**:

- Someone wants to sponsor → they get a slot immediately if one is free
- Group is full → "no slots available" — no queue, no waitlist
- Slot opens (demotion day 8) → cron emails admin; recipient finds someone new through
  their own network and the admin assigns them a slot

This is intentional. Sponsorship is a relationship, not a platform feature. The recipient
knows their sponsors; replacements come through personal outreach, not a managed queue.

**The existing `_waitlist.json`** in the codebase is unused complexity — it can be ignored
or removed.

---

## Live Deployment Status (updated 2026-06-03)

The system is **fully built and working**. Confirmed end-to-end test on 2026-06-03.

### What's running on RN1
- Script: `/root/scripts/sponsorship-reminders.py`
- Cron: installed in root's crontab (verified via `crontab -l`)
- Log: `/var/log/ds-reminders.log`
- Groups: `/var/www/directsponsor.net/userdata/sponsorship-groups/`

### Notification secret — correct file paths
The shared secret must exist in **two places**:

| Server | Path | Permissions |
|--------|------|-------------|
| RN1 (104.168.38.197) | `/root/.ds-notify-secret` | `600 root:root` |
| es3-auth (86.38.200.119) | `/etc/ds-notify-secret` | `640 root:apache` |

> **Note**: The auth server's PHP (`send-notification.php`) reads from `/etc/ds-notify-secret`
> (not `/root/`) because PHP-FPM runs as `apache` and cannot enter `/root` (dir is `0550`).
> This was fixed 2026-06-03. If you ever rebuild the auth server, recreate it:
> ```bash
> echo 'YOUR_SECRET' > /etc/ds-notify-secret && chown root:apache /etc/ds-notify-secret && chmod 640 /etc/ds-notify-secret
> ```

---

## Restoring a Sponsor After Demotion

If a sponsor misses a payment (or you run a test cycle deliberately), use:

**Script**: `/root/scripts/restore-sponsor.py` on RN1

```bash
python3 /root/scripts/restore-sponsor.py kelvin andytest2        # 1 slot
python3 /root/scripts/restore-sponsor.py evans  andytest2 2      # 2 slots
```

What it does:
- Resets `slots` to the given number (or previous value if omitted)
- Sets `last_paid_month` to the current month (so no reminders fire immediately)
- Clears `reminder_state` entirely

The sponsor is immediately active again — no other steps needed. If the username was
fully removed from the group file, the script re-adds them as a fresh entry.

---

## References

- Payment flow: `site/api/sponsorship-api.php` (`pay` action)
- Webhook (records payment): `site/api/webhook.php` (`processSponsorshipPayment`)
- Group data storage: `userdata/sponsorship-groups/{recipient}.json`
- Auth server email: `auth-server/auth/website/magic-login.php` (`sendMagicLinkEmail`)
- Progress notes: `PROGRESS.md` (Sponsorship groups — Phase 3 section)
