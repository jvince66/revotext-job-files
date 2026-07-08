# Architecture notes

Two services, one bucket, two flows. **Bucket versioning is enabled** — overwritten keys retain prior versions.

## Upload flow (presign.revotext.com) — server-to-server

```
Reporter's browser         Coworker's backend         presign.revotext.com               S3
  (assignments.revotext.com)  (their server)
        │                          │                          │                            │
        │  Click Upload            │                          │                            │
        │  POST /file+worksheet    │                          │                            │
        │─────────────────────────▶│                          │                            │
        │                          │  Authenticate reporter   │                            │
        │                          │                          │                            │
        │                          │  GET /?job=X&filename=Y  │                            │
        │                          │  Authorization: Bearer   │                            │
        │                          │     <shared-secret>      │                            │
        │                          │─────────────────────────▶│                            │
        │                          │                          │  Validate bearer token     │
        │                          │                          │  Validate job_id regex     │
        │                          │                          │  Validate filename         │
        │                          │                          │  Rate-limit (300/min)      │
        │                          │                          │  Mint presigned PUT URL    │
        │                          │  200 {uploadUrl, ...}    │                            │
        │                          │◀─────────────────────────│                            │
        │                          │                          │                            │
        │                          │  (Either their backend or the reporter's browser      │
        │                          │   PUTs the file to uploadUrl — decided on their side) │
        │                          │─── PUT uploadUrl ────────┼────────────────────────────▶│
        │                          │                          │                            │  Object created:
        │                          │                          │                            │  jobs/X/Y
        │                          │                          │                            │
        │                          │  200 OK                  │                            │
        │◀─────────────────────────│◀─── 200 OK ──────────────┼────────────────────────────│
```

- Shared-secret bearer token required on every presign call
- Presigned URL: PUT only, 15-min TTL
- Rate limit: 300 requests/minute global (keyed by secret hash — makes it per-caller since only one caller exists today)
- S3 bucket CORS still allows browser PUT from `https://assignments.revotext.com` in case they route uploads through the reporter's browser rather than their backend

## Read flow (files.revotext.com) — browser + M365 SSO

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
     │                                          │  Mint presigned GET URLs (15m)  │
     │  200 {files: [...], viewer: {...}, ...}  │                                 │
     │◀─────────────────────────────────────────│                                 │
     │                                          │                                 │
     │  Click filename → GET presigned_url      │                                 │
     │──────────────────────────────────────────┼────────────────────────────────▶│
     │  200 (file content streamed)             │                                 │
     │◀─────────────────────────────────────────┼─────────────────────────────────│
```

## Shared components on the Lightsail server

- `/var/www/files.revotext.com/vendor-root/vendor/` — AWS SDK for PHP. `presign.revotext.com` uses this via absolute `require_once`.
- `/var/www/html/api/auth-helper.php` — M365 token validation + allowlist check. `files.revotext.com/api/auth-helper.php` is a symlink to this so the two apps stay in sync.
- `/var/www/html/api/users.json` — the M365 email allowlist (owned by the support portal). Both apps read it via the symlink.
- `/root/migration-secrets/presign_endpoint_secret` — the shared bearer secret (root-only readable; shared with the caller out-of-band).

## Why shared-secret bearer auth?

The endpoint used to be anonymous with per-IP rate limiting when it was called directly from the reporter's browser. That model has known limitations: anyone who knows a valid Job ID can mint upload URLs, and only rate-limiting stands between us and abuse.

The current design routes the presign call server-to-server: `assignments.revotext.com`'s backend authenticates the reporter (their own auth), then calls our endpoint with a shared secret. Reporters never call us directly. Benefits:

- **Real authentication.** The bearer secret is never exposed to any browser.
- **Better rate-limit granularity.** All legitimate traffic comes from a single caller, so a global cap makes sense.
- **Cleaner audit trail.** Every presign issuance is attributable to the caller.
- **SOC 2 friendly.** Auditors accept HMAC/bearer between servers as a genuine access control.

The bucket CORS rule and the file-upload path from browser to S3 stays in place so the coworker's design decision (their backend proxying vs. their browser uploading) doesn't force a code change on our side.
