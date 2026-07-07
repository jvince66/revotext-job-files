# CLAUDE.md — revotext-job-files

Context file for Claude sessions working on this repo.

## What this is

Two internal services for handling court reporter turn-in files. See `README.md` for the plain-English overview.

**Bucket:** `revotext-portal-documents` (us-east-1, owned by AWS account 895306629228)
**Folder convention:** `jobs/{JOB_ID}/{filename}`
**Job ID regex:** `^C-\d{3}[A-Z0-9]{2}-\d{3}T?$`

## Deployment target

Both services live on the same Ubuntu Lightsail instance as `support.revotext.com`, at:

| Service | DocumentRoot | Purpose |
|---|---|---|
| `files.revotext.com` | `/var/www/files.revotext.com/public` | Office-side read UI |
| `presign.revotext.com` | `/var/www/presign.revotext.com/public` | Reporter-side upload minter |

Both share the AWS SDK for PHP installed under `/var/www/files.revotext.com/vendor-root/vendor/` (presign.revotext.com's PHP `require`s the absolute path).

## Auth model

**presign.revotext.com** — anonymous, browser-callable. Defenses:
- Job ID regex validation
- Filename sanitization (no `/`, no `..`, no null, whitelist charset)
- Rate limit 60/min per IP (file-based counter in `/tmp/presign-rl/`)
- CORS: only `https://assignments.revotext.com`
- Presigned URL: PUT only, 15-min TTL, S3 bucket CORS restricts origin

**files.revotext.com** — M365 SSO. Uses the shared `/var/www/html/api/auth-helper.php` from the support portal (symlinked, so tenant/client/allowlist stay in one place).

## IAM users (least privilege)

| User | Policy | Used by |
|---|---|---|
| `revotext-files-portal-reader` | `revotext-portal-documents-jobs-read` (`s3:ListBucket` under prefix `jobs/*`, `s3:GetObject` on `jobs/*`) | `files.revotext.com` |
| `revotext-presign-writer` | `revotext-portal-documents-jobs-write` (`s3:PutObject` on `jobs/*` only) | `presign.revotext.com` |

Neither user can delete objects. Deletes are done manually via AWS console.

## Files that must never be committed

- `**/api/aws-config.json` — real IAM keys, always gitignored
- Any `.pem`, `.key` — TLS material lives at `/etc/letsencrypt/live/…` on the server
- Server-side session dirs (`/tmp/presign-rl/`, etc.)

## Rebuilds and manual edits

Whenever you edit a PHP file on Lightsail directly (via `sudo nano` or a WinSCP overwrite), re-sync the change back into this repo, commit, push. The repo is the source of truth for code; the server is deploy state.
