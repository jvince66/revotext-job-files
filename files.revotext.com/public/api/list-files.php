<?php
/**
 * files.revotext.com — Job files listing endpoint
 *
 * Called by /public/jobs/index.php via AJAX with:
 *   Header: Authorization: Bearer <M365 access token>
 *   Query:  ?job=C-60716-002
 *
 * Audit hardenings (2026-07-08):
 * - Presigned GET URLs now force Content-Disposition: attachment + Content-Type:
 *   application/octet-stream. Prevents stored-XSS via an uploaded .html file that
 *   would otherwise render in the browser under S3's origin.
 * - AWS SDK error messages sanitized before echoing (server-side logs full detail).
 * - Correlation ID (X-Request-Id) added.
 */
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once __DIR__ . '/../../vendor-root/vendor/autoload.php';
require_once __DIR__ . '/../../api/auth-helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$requestId = bin2hex(random_bytes(6));
header('X-Request-Id: ' . $requestId);

function fail(int $status, string $error, array $extra = [], string $requestId = ''): void {
    http_response_code($status);
    $body = array_merge(['error' => $error], $extra);
    if ($requestId !== '') $body['request_id'] = $requestId;
    echo json_encode($body);
    exit;
}

// ---- Load AWS config ----
$cfgPath = __DIR__ . '/../../api/aws-config.json';
if (!file_exists($cfgPath)) fail(500, 'server not configured', [], $requestId);
$awsCfg = json_decode((string)file_get_contents($cfgPath), true);
if (!is_array($awsCfg)) fail(500, 'server not configured', [], $requestId);

$region  = (string)($awsCfg['region']            ?? 'us-east-1');
$bucket  = (string)($awsCfg['bucket']            ?? '');
$prefix  = (string)($awsCfg['prefix']            ?? 'jobs/');
$akid    = (string)($awsCfg['access_key_id']     ?? '');
$secret  = (string)($awsCfg['secret_access_key'] ?? '');
$ttl     = (int)   ($awsCfg['presign_ttl_seconds'] ?? 900);
$pattern = (string)($awsCfg['job_id_pattern']    ?? '/^C-\d{3}[A-Z0-9]{2}-\d{3}T?$/');

if ($bucket === '' || $akid === '' || $secret === '') fail(500, 'server not configured', [], $requestId);
if (str_starts_with($akid, '<') || str_starts_with($secret, '<')) fail(500, 'server not configured', [], $requestId);

// ---- Auth ----
$token = auth_bearer_token();
if (!$token) fail(401, 'missing bearer token', [], $requestId);
$caller = auth_resolve_graph_caller($token);
if (!$caller || empty($caller['email'])) fail(401, 'token validation failed', [], $requestId);
if (!auth_email_in_allowlist($caller['email'])) fail(403, 'not in portal allowlist', ['email' => $caller['email']], $requestId);

// ---- Validate Job ID ----
$jobId = trim((string)($_GET['job'] ?? ''));
if ($jobId === '') fail(400, 'job is required', [], $requestId);
if (!preg_match($pattern, $jobId)) fail(400, 'invalid job_id shape', ['job' => $jobId], $requestId);

// ---- S3 list + presign (with forced attachment disposition) ----
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
    error_log(sprintf("[list-files] rid=%s aws error code=%s type=%s msg=%s",
        $requestId, $e->getAwsErrorCode(), $e->getAwsErrorType(), $e->getMessage()));
    fail(502, 'upstream error', [], $requestId);
} catch (\Throwable $e) {
    error_log(sprintf("[list-files] rid=%s error class=%s msg=%s",
        $requestId, get_class($e), $e->getMessage()));
    fail(500, 'internal error', [], $requestId);
}

$truncated = !empty($result['IsTruncated']);

$files = [];
foreach (($result['Contents'] ?? []) as $obj) {
    $key = (string)($obj['Key'] ?? '');
    if ($key === '' || $key === $folderPrefix) continue;
    $baseName = basename($key);
    // Force attachment download — prevents browser from rendering uploaded .html/.svg as active content
    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => $bucket,
        'Key'    => $key,
        'ResponseContentDisposition' => 'attachment; filename="' . str_replace('"', '', $baseName) . '"',
        'ResponseContentType' => 'application/octet-stream',
    ]);
    $presigned = (string)$s3->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    $lastMod = $obj['LastModified'] ?? null;
    if ($lastMod instanceof DateTimeInterface) $lastMod = $lastMod->format('c');
    $files[] = [
        'key'           => $key,
        'name'          => $baseName,
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
    'truncated'   => $truncated,
    'files'       => $files,
    'viewer'      => [
        'email' => $caller['email'],
        'name'  => $caller['name'] ?? '',
    ],
    'request_id'  => $requestId,
]);
