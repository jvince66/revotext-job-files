# AWS resources

Bucket: `revotext-portal-documents` (us-east-1, account 895306629228).

## Bucket versioning

**Enabled.** Every overwrite of an existing key preserves the prior version. If a reporter re-uploads a file with the same name, the earlier version is retained and can be recovered via the S3 console or CLI. Also protects against accidental deletion (though our IAM policies already disallow delete for both users).

Lifecycle policy for non-current versions: not currently set. Consider adding a rule to expire non-current versions after N days if storage cost becomes a concern.

## IAM users

| User | Attached policy | Consumer |
|---|---|---|
| `revotext-files-portal-reader` | `revotext-portal-documents-jobs-read` | `files.revotext.com` (office read side) |
| `revotext-presign-writer` | `revotext-portal-documents-jobs-write` | `presign.revotext.com` (reporter write side) |

Neither user can delete objects. Delete via AWS console when needed.

## Policies

- `iam-policy-jobs-read.json` — Attach to `revotext-files-portal-reader`. Grants `s3:ListBucket` (prefix `jobs/*`) and `s3:GetObject` on `jobs/*`.
- `iam-policy-jobs-write.json` — Attach to `revotext-presign-writer`. Grants only `s3:PutObject` on `jobs/*`.

## S3 bucket CORS

- `s3-bucket-cors.json` — Applied via **S3 → Permissions → Cross-origin resource sharing**. Allows browser PUT from `https://assignments.revotext.com`. Still applied for defense-in-depth even though the current design routes uploads through the coworker's backend.

## Rotations

- **IAM access keys** — every 90 days. Update `aws-config.json` on Lightsail. No downtime.
- **`presign.revotext.com` endpoint_secret** — every 90 days. See `presign.revotext.com/README.md` for the rotation procedure.
