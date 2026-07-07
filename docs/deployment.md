# Deployment runbook

Both services live on the same Ubuntu Lightsail instance as `support.revotext.com`.

## Prerequisites (one-time, per service)

- DNS A record pointing the subdomain at the Lightsail public IP
- Apache 2.4 + PHP 8.3 + certbot already installed (already present on the box for support.revotext.com)
- AWS SDK for PHP installed under `/var/www/files.revotext.com/vendor-root/vendor/` (installed once for `files.revotext.com`; `presign.revotext.com` shares it via absolute `require`)

## Deploy `files.revotext.com`

```bash
# 1. Doc root
sudo mkdir -p /var/www/files.revotext.com/public/api /var/www/files.revotext.com/public/jobs /var/www/files.revotext.com/api
sudo chown -R www-data:www-data /var/www/files.revotext.com
sudo chmod 750 /var/www/files.revotext.com/api

# 2. Copy source files from this repo
sudo cp files.revotext.com/public/index.html /var/www/files.revotext.com/public/index.html
sudo cp files.revotext.com/public/.htaccess /var/www/files.revotext.com/public/.htaccess
sudo cp files.revotext.com/public/api/list-files.php /var/www/files.revotext.com/public/api/list-files.php
sudo cp files.revotext.com/public/jobs/index.php /var/www/files.revotext.com/public/jobs/index.php

# 3. Symlink auth-helper.php from the support portal
sudo ln -sf /var/www/html/api/auth-helper.php /var/www/files.revotext.com/api/auth-helper.php

# 4. Real aws-config.json (fill in IAM keys before saving)
sudo cp files.revotext.com/api/aws-config.example.json /var/www/files.revotext.com/api/aws-config.json
sudo nano /var/www/files.revotext.com/api/aws-config.json   # fill in access_key_id and secret_access_key
sudo chown www-data:www-data /var/www/files.revotext.com/api/aws-config.json
sudo chmod 640 /var/www/files.revotext.com/api/aws-config.json

# 5. Apache vhost
sudo cp files.revotext.com/apache/files.revotext.com.conf /etc/apache2/sites-available/
sudo a2ensite files.revotext.com.conf
sudo apache2ctl configtest && sudo systemctl reload apache2

# 6. TLS cert (adds the port-443 vhost automatically)
sudo certbot --apache -d files.revotext.com --non-interactive --agree-tos --redirect --email support@revotext.com
```

## Deploy `presign.revotext.com`

```bash
# 1. Doc root
sudo mkdir -p /var/www/presign.revotext.com/public /var/www/presign.revotext.com/api
sudo chown -R www-data:www-data /var/www/presign.revotext.com
sudo chmod 750 /var/www/presign.revotext.com/api

# 2. Copy source
sudo cp presign.revotext.com/public/index.php /var/www/presign.revotext.com/public/index.php

# 3. aws-config.json (fill in IAM keys)
sudo cp presign.revotext.com/api/aws-config.example.json /var/www/presign.revotext.com/api/aws-config.json
sudo nano /var/www/presign.revotext.com/api/aws-config.json
sudo chown www-data:www-data /var/www/presign.revotext.com/api/aws-config.json
sudo chmod 640 /var/www/presign.revotext.com/api/aws-config.json

# 4. Apache vhost
sudo cp presign.revotext.com/apache/presign.revotext.com.conf /etc/apache2/sites-available/
sudo a2ensite presign.revotext.com.conf
sudo apache2ctl configtest && sudo systemctl reload apache2

# 5. TLS cert
sudo certbot --apache -d presign.revotext.com --non-interactive --agree-tos --redirect --email support@revotext.com
```

## AWS side (one-time per bucket)

Apply the IAM policies (`aws/iam-policy-*.json`) to their respective users in the AWS console. Apply the S3 CORS JSON (`aws/s3-bucket-cors.json`) to the bucket via S3 → Permissions → Cross-origin resource sharing.

## Smoke tests

After deploying each service, run these from the Lightsail SSH:

### files.revotext.com

```bash
# Should return 401 missing bearer token
curl -sw "\nHTTP: %{http_code}\n" "https://files.revotext.com/api/list-files.php?job=C-60716-002"
```

Then open `https://files.revotext.com/jobs/C-60716-002` in a browser, sign in with M365, confirm the file list renders.

### presign.revotext.com

```bash
# Should return JSON with uploadUrl
curl -s "https://presign.revotext.com/?job=C-60716-002&filename=smoketest.txt" | python3 -m json.tool
```

Then PUT a small file to the returned uploadUrl (see `docs/architecture.md` for the flow). Verify the object shows up in S3 and in the office browse UI.

## Rotation / update flow

To ship a code change: edit the file in this repo → commit → push → SSH to Lightsail → `git pull` in `~/revotext-job-files/` (or WinSCP the changed file) → `sudo cp` into the doc root → `sudo systemctl reload apache2` if the config changed.

No downtime for PHP-only changes; Apache reload is instant.
