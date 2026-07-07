# AWS resources

Bucket: `revotext-portal-documents` (us-east-1, account 895306629228).

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

- `s3-bucket-cors.json` — Applied to the bucket via **S3 → Permissions → Cross-origin resource sharing**. Allows browser PUT from `https://assignments.revotext.com` only. Without this, browser-side uploads from the reporter's worksheet card would be blocked.

## Rotation

Rotate both IAM users' access keys every 90 days. Update the corresponding `aws-config.json` on the Lightsail server. No downtime — the new key becomes live the moment the JSON is saved (PHP re-reads on each request).
