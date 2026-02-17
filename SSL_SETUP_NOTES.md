# SSL Setup & Server Migration Notes
Date: 2025-12-16

## Current Status: BLOCKED (Wait 24h)
**Next Attempt Time:** After Dec 16, 17:00 PST (approx)

## Issue Summary
We are migrating `directsponsor.net` to the new Debian 13 VPS (RN1).
The SSL setup via `acme.sh` failed because the default CA (**ZeroSSL**) blocked the domain for 24 hours (`retryafter=86400`).

### Root Cause Analysis
The 24-hour block was triggered by repeated validation failures.
- **Problem:** Apache was returning `404 Not Found` for the ACME validation challenge files (e.g., `.well-known/acme-challenge/random-string`).
- **Diagnosis:** Confirmed via `curl` returning 404 for test files, even though they existed on disk.
- **Fix:** We diagnosed and fixed the Apache/Permission issue. Test files are now accessible via HTTP 200 OK.

## Next Steps (Action Required)
Once the 24-hour block expires, run the following command on the VPS (RN1) to issue the certificate:

```bash
# SSH into the server first
ssh RN1

# Run acme.sh to issue the cert
~/.acme.sh/acme.sh --issue -d directsponsor.net -d www.directsponsor.net --apache
```

## After Successful Issuance
Once the command above succeeds:
1. **Install the Cert** (if not auto-installed):
   ```bash
   ~/.acme.sh/acme.sh --install-cert -d directsponsor.net \
      --cert-file      /etc/ssl/certs/directsponsor.net.cert  \
      --key-file       /etc/ssl/private/directsponsor.net.key  \
      --fullchain-file /etc/ssl/certs/directsponsor.net.fullchain.cer \
      --reloadcmd     "systemctl reload apache2"
   ```
2. **Verify HTTPS:** Check `https://directsponsor.net` in a browser.
