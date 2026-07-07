# Architecture notes

Two services, one bucket, two flows.

## Upload flow (presign.revotext.com)

```
Reporter's browser                       presign.revotext.com                    S3
     │                                          │                                 │
     │  Click Upload on worksheet card          │                                 │
     │  GET /?job=C-60716-002&filename=X.pdf    │                                 │
     │─────────────────────────────────────────▶│                                 │
     │                                          │  Validate job_id regex          │
     │                                          │  Validate filename              │
     │                                          │  Rate-limit check (60/min/IP)   │
     │                                          │  Mint presigned PUT URL         │
     │  200 {uploadUrl, expiresIn, key, ...}    │  (via AWS SDK for PHP)          │
     │◀─────────────────────────────────────────│                                 │
     │                                          │                                 │
     │  PUT uploadUrl                           │                                 │
     │  Body: file bytes                        │                                 │
     │──────────────────────────────────────────┼────────────────────────────────▶│
     │                                          │                                 │  Object created:
     │                                          │                                 │  jobs/C-60716-002/X.pdf
     │  200 OK                                  │                                 │
     │◀─────────────────────────────────────────┼─────────────────────────────────│
```

- Presigned URL lifetime: 15 minutes
- S3 bucket CORS allows PUT from `https://assignments.revotext.com` only (the reporter's worksheet origin)
- Content-length cap: 500 MB per file (enforced by the client-side upload code; not signed into the URL itself in this build)

## Read flow (files.revotext.com)

```
Office staff browser                     files.revotext.com                       S3
     │                                          │                                 │
     │  GET /jobs/C-60716-002                   │                                 │
     │─────────────────────────────────────────▶│  Serve jobs/index.php           │
     │                                          │  (loads MSAL.js)                │
     │◀─────────────────────────────────────────│                                 │
     │                                          │                                 │
     │  MSAL popup: sign in with M365           │                                 │
     │  Popup returns access token              │                                 │
     │                                          │                                 │
     │  GET /api/list-files.php?job=C-60716-002 │                                 │
     │  Authorization: Bearer <MS token>        │                                 │
     │─────────────────────────────────────────▶│  auth_resolve_graph_caller()    │
     │                                          │  auth_email_in_allowlist()      │
     │                                          │  Validate job_id regex          │
     │                                          │  S3 listObjectsV2               │
     │                                          │────────────────────────────────▶│
     │                                          │◀────────────────────────────────│
     │                                          │  For each object, mint          │
     │                                          │  presigned GET URL (15 min)     │
     │  200 {files: [...], viewer: {...}, ...}  │                                 │
     │◀─────────────────────────────────────────│                                 │
     │                                          │                                 │
     │  Render HTML table with download links   │                                 │
     │                                          │                                 │
     │  Click filename → GET presigned_url      │                                 │
     │──────────────────────────────────────────┼────────────────────────────────▶│
     │  200 (file content streamed)             │                                 │
     │◀─────────────────────────────────────────┼─────────────────────────────────│
```

- Office read is fully gated by M365 SSO (same tenant + allowlist as `support.revotext.com`)
- Download links are per-request short-lived — each click on a filename mints a fresh 15-min URL server-side
- Office side has **no delete or upload permission**; only read

## Shared components on the Lightsail server

- `/var/www/files.revotext.com/vendor-root/vendor/` — AWS SDK for PHP. `presign.revotext.com` uses this same installation via absolute `require_once` path
- `/var/www/html/api/auth-helper.php` — M365 token validation + allowlist check (owned by the support portal repo; `files.revotext.com/api/auth-helper.php` is a symlink to this)
- `/var/www/html/api/users.json` — the allowlist (owned by the support portal). Add or remove office staff there and both apps see the change

## Why no HMAC on presign

The reporter's browser calls `presign.revotext.com` directly. Any shared secret would be visible in DevTools, so HMAC would provide zero real defense. Instead we rely on:

- **Job ID regex + filename sanitization** (blocks garbage input)
- **Rate limit** (blocks brute-force)
- **CORS** (blocks direct calls from other origins in a browser context)
- **Short-lived, single-key presigned URLs** (blocks reuse / forwarding)

For a stronger posture (SOC 2 leaning), the coworker's platform would call our endpoint server-to-server with HMAC, and hand the URL to the reporter's browser. Not implemented in this build — see conversation history if you want to revisit.
