# CLAUDE.md — revotext-job-files

Context file for Claude sessions working on this repo.

## What this is

Two internal services for handling court reporter turn-in files. See `README.md` for the plain-English overview.

**Bucket:** `revotext-portal-documents` (us-east-1, owned by AWS account 895306629228). Versioning **enabled** — overwrites preserve the prior version.
**Folder convention:** `jobs/{JOB_ID}/{filename}`
**Job ID regex:** `^C-\d{3}[A-Z0-9]{2}-\d{3}T?$`

## Deployment target

Both services live on the same Ubuntu Lightsail instance as `support.revotext.com`, at:

| Service | DocumentRoot | Purpose |
|---|---|---|
| `files.revotext.com` | `/var/www/files.revotext.com/public` | Office-side read UI (M365 SSO) |
| `presign.revotext.com` | `/var/www/presign.revotext.com/public` | Server-to-server upload URL minter |

Both share the AWS SDK for PHP installed under `/var/www/files.revotext.com/vendor-root/vendor/` (presign.revotext.com's PHP `require`s the absolute path).

## Auth model

### presign.revotext.com — **server-to-server only**

**Not** browser-callable. The coworker's server (`assignments.revotext.com` backend) authenticates the reporter, then calls this endpoint on the reporter's behalf. Reporter's browser then PUTs to the returned presigned S3 URL directly.

Defenses (all fail-closed):
- `Authorization: Bearer <shared-secret>` — 64-byte hex secret in `aws-config.json` under `endpoint_secret`. Compared with `hash_equals` (timing-safe). Rotate by phone, never email.
- Global rate limit: **300 req/min** keyed by SHA-256(secret), file-based counter with `flock`.
- Per-IP burst limit: **60 req/min** — defense in depth if the secret leaks and attacker fans across IPs.
- Counter dir: `/var/lib/presign-rl` (owned by `www-data:www-data`). **Not `/tmp/`** because Apache's systemd unit has `PrivateTmp=yes` which sandboxes /tmp and would defeat the cron cleanup.
- Cron cleanup: `/etc/cron.hourly/presign-rl-cleanup` purges counters older than 10 min.
- Job ID regex + filename charset whitelist + null/`..`/path-separator rejection.
- Correlation ID (`X-Request-Id`) on every response — echoed in JSON body too, logged to Apache error log.
- AWS SDK errors are sanitized before echoing to caller (`upstream error` + request_id; full detail server-side only).
- Presigned URL: PUT only, 15-min TTL. S3 bucket CORS additionally restricts browser origins to `https://assignments.revotext.com`.

### files.revotext.com — M365 SSO

Uses the shared `/var/www/html/api/auth-helper.php` from the support portal (symlinked, so tenant/client/allowlist stay in one place). Validated as of 2026-07-08 audit: does proper `tid` / `azp` / `aud` claim checks.

Additional hardenings:
- Presigned GET URLs force `Content-Disposition: attachment; filename=…` + `Content-Type: application/octet-stream` — kills stored-XSS via malicious `.html` uploads.
- `list-files` reports `truncated: true` if a job has >1000 files.
- Same correlation ID + sanitized AWS error handling as presign.
- MSAL CDN script protected by SRI (`sha384-…`) + `crossorigin=anonymous`.

## IAM users (least privilege)

| User | Policy | Used by |
|---|---|---|
| `revotext-files-portal-reader` | `revotext-portal-documents-jobs-read` (`s3:ListBucket` under prefix `jobs/*`, `s3:GetObject` on `jobs/*`) | `files.revotext.com` |
| `revotext-presign-writer` | `revotext-portal-documents-jobs-write` (`s3:PutObject` on `jobs/*` only) | `presign.revotext.com` |

Neither user can delete objects. Deletes are done manually via AWS console.

## Server-wide hardenings (applied via `/etc/apache2/conf-available/security-headers.conf`)

- HSTS: `max-age=63072000; includeSubDomains`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), camera=(), microphone=()`
- `X-Powered-By` stripped
- `.git/` and `.env` globally denied
- Per-vhost: `X-Frame-Options: DENY` + full CSP (files.revotext.com: allows `cdn.jsdelivr.net`, `login.microsoftonline.com`, `graph.microsoft.com`, `*.s3.amazonaws.com`)
- `ServerTokens Prod` + `ServerSignature Off` in `/etc/apache2/conf-enabled/security.conf`
- UFW enabled: allow 22/80/443 in, deny else in, allow out.
- `CGIPassAuth On` set inside `<Directory>` blocks on both vhosts (future-proofing for PHP-FPM cutover; needed for Authorization header to reach PHP).

## Files that must never be committed

- `**/api/aws-config.json` — real IAM keys + endpoint secret. Always gitignored.
- Any `.pem`, `.key` — TLS material lives at `/etc/letsencrypt/live/…` on the server.
- Server-side session/counter dirs (`/var/lib/presign-rl/`).
- Backups (`*.bak.*`) generated during hardening pastes.

## Rebuilds and manual edits

Whenever you edit a PHP file on Lightsail directly (via `sudo nano` or a WinSCP overwrite), re-sync the change back into this repo, commit, push. The repo is the source of truth for code; the server is deploy state.

## Where secrets live (server only)

| File | Contents |
|---|---|
| `/var/www/presign.revotext.com/api/aws-config.json` | `access_key_id`, `secret_access_key` (presign-writer IAM), `endpoint_secret` (Bearer token), bucket/region/CORS/rate-limit config |
| `/var/www/files.revotext.com/api/aws-config.json` | `access_key_id`, `secret_access_key` (portal-reader IAM), bucket/region config |
| `/root/migration-secrets/presign_endpoint_secret` | Copy of the Bearer secret (600 root-only) for share-by-phone |

## Sanity checks before pushing a change

```
# Repo-side
grep -n "endpoint_secret\|access_key_id" -r . --include="*.php"       # should return only the .php files that READ these, never inline values
grep -n "presign-rl" -r . --include="*.php"                           # should show /var/lib/presign-rl only

# Server-side (SSH to Lightsail)
sudo php -l /var/www/presign.revotext.com/public/index.php
sudo php -l /var/www/files.revotext.com/public/api/list-files.php
sudo php -l /var/www/files.revotext.com/public/jobs/index.php
```

## Change history — audit hardenings

**2026-07-08 — MEDIUM audit fixes**
- Per-IP burst limit (60/min, fail-closed) added on top of global 300/min.
- Rate-limit counter dir moved from `/tmp/presign-rl` to `/var/lib/presign-rl` (out of Apache's PrivateTmp namespace, into cron reach).
- `/etc/cron.hourly/presign-rl-cleanup` purges files >10 min old.
- SRI (`sha384-…`) + `crossorigin=anonymous` on the MSAL CDN script.
- UFW firewall enabled (22/80/443 in).

**2026-07-08 — Critical/High audit fixes**
- Rate limiter now fails closed on filesystem errors (was fail-open).
- AWS SDK error messages sanitized before echoing to caller.
- Presigned GET URLs force download disposition + octet-stream (stored-XSS kill).
- `X-Request-Id` correlation IDs on every response.
- Apache security headers globally applied.
- `CGIPassAuth On` inside Directory blocks.
- World-readable config files chmoded to 640.

**Earlier — coworker refinements**
- S3 versioning enabled on `revotext-portal-documents`.
- Bearer-token auth on presign endpoint.
- Rate limit reworked from per-IP to global-keyed-by-secret.
