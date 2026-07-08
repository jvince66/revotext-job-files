# Deployment runbook

Both services live on the same Ubuntu Lightsail instance as `support.revotext.com`.

## Prerequisites (one-time, per service)

- DNS A record pointing the subdomain at the Lightsail public IP
- Apache 2.4 + PHP 8.3 + certbot already installed (already present on the box for support.revotext.com)
- AWS SDK for PHP installed under `/var/www/files.revotext.com/vendor-root/vendor/` (installed once for `files.revotext.com`; `presign.revotext.com` shares it via absolute `require`)

## Deploy `files.revotext.com`

```bash
sudo mkdir -p /var/www/files.revotext.com/public/api /var/www/files.revotext.com/public/jobs /var/www/files.revotext.com/api
sudo chown -R www-data:www-data /var/www/files.revotext.com
sudo chmod 750 /var/www/files.revotext.com/api

# Copy source
sudo cp files.revotext.com/public/index.html /var/www/files.revotext.com/public/index.html
sudo cp files.revotext.com/public/.htaccess /var/www/files.revotext.com/public/.htaccess
sudo cp files.revotext.com/public/api/list-files.php /var/www/files.revotext.com/public/api/list-files.php
sudo cp files.revotext.com/public/jobs/index.php /var/www/files.revotext.com/public/jobs/index.php

# Symlink auth-helper.php from the support portal
sudo ln -sf /var/www/html/api/auth-helper.php /var/www/files.revotext.com/api/auth-helper.php

# aws-config.json (fill in real IAM keys)
sudo cp files.revotext.com/api/aws-config.example.json /var/www/files.revotext.com/api/aws-config.json
sudo nano /var/www/files.revotext.com/api/aws-config.json
sudo chown www-data:www-data /var/www/files.revotext.com/api/aws-config.json
sudo chmod 640 /var/www/files.revotext.com/api/aws-config.json

# Apache vhost + TLS
sudo cp files.revotext.com/apache/files.revotext.com.conf /etc/apache2/sites-available/
sudo a2ensite files.revotext.com.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d files.revotext.com --non-interactive --agree-tos --redirect --email support@revotext.com
```

## Deploy `presign.revotext.com`

```bash
sudo mkdir -p /var/www/presign.revotext.com/public /var/www/presign.revotext.com/api
sudo chown -R www-data:www-data /var/www/presign.revotext.com
sudo chmod 750 /var/www/presign.revotext.com/api

# Copy source
sudo cp presign.revotext.com/public/index.php /var/www/presign.revotext.com/public/index.php

# Generate shared secret
SECRET=$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 48)
sudo mkdir -p /root/migration-secrets
echo "$SECRET" | sudo tee /root/migration-secrets/presign_endpoint_secret > /dev/null
sudo chmod 600 /root/migration-secrets/presign_endpoint_secret

# aws-config.json (fill in IAM keys, add endpoint_secret)
sudo cp presign.revotext.com/api/aws-config.example.json /var/www/presign.revotext.com/api/aws-config.json
sudo nano /var/www/presign.revotext.com/api/aws-config.json  # paste the IAM keys, and paste $SECRET as endpoint_secret
sudo chown www-data:www-data /var/www/presign.revotext.com/api/aws-config.json
sudo chmod 640 /var/www/presign.revotext.com/api/aws-config.json

# Apache vhost + TLS
sudo cp presign.revotext.com/apache/presign.revotext.com.conf /etc/apache2/sites-available/
sudo a2ensite presign.revotext.com.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d presign.revotext.com --non-interactive --agree-tos --redirect --email support@revotext.com

# Share the secret with the caller out-of-band (phone)
sudo cat /root/migration-secrets/presign_endpoint_secret
unset SECRET
```

## AWS side (one-time per bucket)

Apply the IAM policies to their users. Apply the S3 CORS JSON via S3 → Permissions → Cross-origin resource sharing. **Enable Bucket Versioning** via S3 → Properties → Bucket Versioning → Edit → Enable.

## Smoke tests

### files.revotext.com

```bash
# Expect 401 missing bearer token
curl -sw "\nHTTP: %{http_code}\n" "https://files.revotext.com/api/list-files.php?job=C-60716-002"
```

Then open `https://files.revotext.com/jobs/C-60716-002` in a browser, sign in with M365, confirm the file list renders.

### presign.revotext.com

```bash
# Test 1: no header → 401 unauthorized
curl -sw "\nHTTP: %{http_code}\n" "https://presign.revotext.com/?job=C-60716-002&filename=t.txt"

# Test 2: valid secret → 200 with uploadUrl
SECRET=$(sudo cat /root/migration-secrets/presign_endpoint_secret)
curl -s -H "Authorization: Bearer $SECRET" "https://presign.revotext.com/?job=C-60716-002&filename=t.txt" | python3 -m json.tool
unset SECRET
```

Then PUT a small file to the returned `uploadUrl` and verify it lands in the bucket + shows up in the office browse UI.

## Update flow

To ship a code change: edit in this repo → commit → push → SSH to Lightsail → WinSCP the changed file into place (or `git pull` if git-based deploy is set up) → `sudo systemctl reload apache2` if the vhost changed. PHP-only changes are picked up on the next request; no reload needed.
