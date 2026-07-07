<?php
/**
 * files.revotext.com — Job files listing endpoint
 *
 * Called by /public/jobs/index.php via AJAX with:
 *   Header: Authorization: Bearer <M365 access token>
 *   Query:  ?job=C-60716-002
 *
 * Auth flow mirrors /var/www/html/api/monday.php — auth-helper.php is a
 * symlink from there so tenant/client/allowlist stay in one place.
 */
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once __DIR__ . '/../vendor-root/vendor/autoload.php';
require_once __DIR__ . '/auth-helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function fail(int $status, string $error, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['error' => $error], $extra));
    exit;
}

// ---- Load AWS config ----
$cfgPath = __DIR__ . '/aws-config.json';
if (!file_exists($cfgPath)) fail(500, 'aws-config.json not present on server');
$awsCfg = json_decode((string)file_get_contents($cfgPath), true);
if (!is_array($awsCfg)) fail(500, 'aws-config.json malformed');

$region  = (string)($awsCfg['region']            ?? 'us-east-1');
$bucket  = (string)($awsCfg['bucket']            ?? '');
$prefix  = (string)($awsCfg['prefix']            ?? 'jobs/');
$akid    = (string)($awsCfg['access_key_id']     ?? '');
$secret  = (string)($awsCfg['secret_access_key'] ?? '');
$ttl     = (int)   ($awsCfg['presign_ttl_seconds'] ?? 900);
$pattern = (string)($awsCfg['job_id_pattern']    ?? '/^C-\d{3}[A-Z0-9]{2}-\d{3}T?$/');

if ($bucket === '' || $akid === '' || $secret === '') fail(500, 'AWS credentials or bucket not configured');
if (str_starts_with($akid, '<') || str_starts_with($secret, '<')) fail(500, 'AWS credentials are still placeholders — edit aws-config.json');

// ---- Auth ----
$token = auth_bearer_token();
if (!$token) fail(401, 'missing bearer token');
$caller = auth_resolve_graph_caller($token);
if (!$caller || empty($caller['email'])) fail(401, 'token validation failed');
if (!auth_email_in_allowlist($caller['email'])) fail(403, 'not in portal allowlist', ['email' => $caller['email']]);

// ---- Validate Job ID ----
$jobId = trim((string)($_GET['job'] ?? ''));
if ($jobId === '') fail(400, 'job is required');
if (!preg_match($pattern, $jobId)) fail(400, 'invalid job_id shape', ['job' => $jobId]);

// ---- S3 list + presign ----
$folderPrefix = $prefix . $jobId . '/';

try {
    $s3 = new S3Client([
        'version' => 'latest',
        'region'  => $region,
        'credentials' => ['key' => $akid, 'secret' => $secret],
    ]);
    $result = $s3->listObjectsV2([
        'Bucket'  => $bucket,
        'Prefix'  => $folderPrefix,
        'MaxKeys' => 1000,
    ]);
} catch (AwsException $e) {
    fail(502, 'S3 error: ' . $e->getAwsErrorCode() . ' — ' . $e->getMessage());
} catch (\Throwable $e) {
    fail(500, 'S3 client init failed: ' . $e->getMessage());
}

$files = [];
foreach (($result['Contents'] ?? []) as $obj) {
    $key = (string)($obj['Key'] ?? '');
    if ($key === '' || $key === $folderPrefix) continue;
    $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
    $presigned = (string)$s3->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    $lastMod = $obj['LastModified'] ?? null;
    if ($lastMod instanceof DateTimeInterface) $lastMod = $lastMod->format('c');
    $files[] = [
        'key'           => $key,
        'name'          => basename($key),
        'size'          => (int)($obj['Size'] ?? 0),
        'last_modified' => $lastMod,
        'download_url'  => $presigned,
    ];
}

usort($files, fn($a, $b) => strcmp((string)($b['last_modified'] ?? ''), (string)($a['last_modified'] ?? '')));

echo json_encode([
    'success'     => true,
    'job_id'      => $jobId,
    'bucket'      => $bucket,
    'prefix'      => $folderPrefix,
    'presign_ttl' => $ttl,
    'file_count'  => count($files),
    'files'       => $files,
    'viewer'      => [
        'email' => $caller['email'],
        'name'  => $caller['name'] ?? '',
    ],
]);
