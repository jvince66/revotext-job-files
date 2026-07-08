# revotext-job-files

Two web services that manage court reporter turn-in files stored in the S3 bucket `revotext-portal-documents`. Deployed on the same Lightsail Ubuntu instance as `support.revotext.com` and `revotext.com`.

- **`presign.revotext.com`** — server-to-server endpoint that mints short-lived, presigned S3 upload URLs. Callers authenticate with a shared bearer secret. Called by the coworker's backend at `assignments.revotext.com`; reporters never touch this endpoint directly.
- **`files.revotext.com`** — M365-SSO-gated file browser used by office staff to review turn-in files at approval time. URL shape: `https://files.revotext.com/jobs/{JOB_ID}`. Fetches the same S3 folder and hands back short-lived presigned download URLs.

Same bucket. Same folder convention: `jobs/{JOB_ID}/{filename}`. Different IAM roles (write-only vs. read-only). Bucket versioning is enabled — re-uploaded files preserve prior versions.

## Folder layout

```
files.revotext.com/          Office read side — vhost, PHP source, config templates
presign.revotext.com/        Reporter write side — vhost, PHP source, config templates
aws/                         IAM policy JSON + S3 CORS JSON
docs/                        Deployment runbook + architecture notes
```

## Job ID convention

`^C-\d{3}[A-Z0-9]{2}-\d{3}T?$` — e.g. `C-60716-002`.
Where the fields are: `C-YMMLL-NNN[T]`. `Y` = year digit, `MM` = month, `LL` = court district (2 alphanumeric), `NNN` = sequential per district per month, optional `T` suffix.

Files land in S3 under `jobs/C-60716-002/{filename}`. Both services enforce this pattern before any S3 operation.

## Deploy

See [`docs/deployment.md`](docs/deployment.md).

## Architecture

See [`docs/architecture.md`](docs/architecture.md).
