<?php
/**
 * presign.revotext.com — S3 upload presign endpoint
 *
 * Server-to-server. Coworker's server (assignments.revotext.com backend) calls this
 * after authenticating the reporter. Reporter's browser never talks to us directly.
 *
 * Request:
 *   GET /?job=C-60716-002&filename=worksheet.pdf
 *   Authorization: Bearer <shared-secret>
 *
 * Response:
 *   {"uploadUrl":"https://...","expiresIn":900,"key":"jobs/.../","method":"PUT","maxBytes":524288000}
 */
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

require_once '/var/www/files.revotext.com/vendor-root/vendor/autoload.php';

$cfg = @json_decode((string)@file_get_contents('/var/www/presign.revotext.com/api/aws-config.json'), true);
if (!is_array($cfg)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'server not configured']);
    exit;
}

// ---- CORS (still supported in case a browser fallback is ever needed) ----
$allowedOrigin = (string)($cfg['allowed_origin'] ?? '');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowedOrigin && $allowedOrigin !== '') {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 3600');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ---- Bearer token auth (shared secret) ----
function get_bearer_token(): ?string {
    $hdr = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
        }
    }
    if (!$hdr && isset($_SERVER['HTTP_AUTHORIZATION']))          $hdr = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (!$hdr && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (!$hdr) return null;
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($hdr), $m)) return null;
    return trim($m[1]);
}

$expectedSecret = (string)($cfg['endpoint_secret'] ?? '');
if ($expectedSecret === '' || strlen($expectedSecret) < 32) fail(500, 'server not configured');

$token = get_bearer_token();
if ($token === null || !hash_equals($expectedSecret, $token)) {
    error_log(sprintf("[presign] unauthorized from ip=%s ua=%s", $_SERVER['REMOTE_ADDR'] ?? '?', $_SERVER['HTTP_USER_AGENT'] ?? '?'));
    fail(401, 'unauthorized');
}

// ---- Rate limit: 300/min global (keyed by secret hash, not IP) ----
$secretHash = substr(hash('sha256', $expectedSecret), 0, 16);
$rlDir = '/tmp/presign-rl';
@mkdir($rlDir, 0755, true);
$minuteBucket = intdiv(time(), 60);
$rlFile = $rlDir . '/' . $secretHash . '.' . $minuteBucket;
$fp = @fopen($rlFile, 'c+');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        $count = (int)stream_get_contents($fp) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$count);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($count > 300) fail(429, 'rate limit exceeded');
    } else {
        fclose($fp);
    }
}

// ---- Config ----
$region   = (string)($cfg['region']              ?? 'us-east-1');
$s3bucket = (string)($cfg['bucket']              ?? 'revotext-portal-documents');
$prefix   = (string)($cfg['prefix']              ?? 'jobs/');
$akid     = (string)($cfg['access_key_id']       ?? '');
$secret   = (string)($cfg['secret_access_key']   ?? '');
$ttl      = (int)   ($cfg['presign_ttl_seconds'] ?? 900);
$maxBytes = (int)   ($cfg['max_upload_bytes']    ?? 524288000);
$pattern  = (string)($cfg['job_id_pattern']      ?? '/^C-\d{3}[A-Z0-9]{2}-\d{3}T?$/');

if ($s3bucket === '' || $akid === '' || $secret === '') fail(500, 'server not configured');

// ---- Validate input ----
$jobId = trim((string)($_GET['job'] ?? ''));
$filename = trim((string)($_GET['filename'] ?? ''));

if ($jobId === '') fail(400, 'job is required');
if (!preg_match($pattern, $jobId)) fail(400, 'invalid job_id');

if ($filename === '') fail(400, 'filename is required');
if (strlen($filename) > 255) fail(400, 'filename too long');
if (preg_match('#[/\\\\]#', $filename)) fail(400, 'filename may not contain path separators');
if (str_contains($filename, '..')) fail(400, 'filename may not contain ..');
if (str_contains($filename, "\0")) fail(400, 'invalid filename');
if (!preg_match('/^[A-Za-z0-9._\-() ]+$/', $filename)) fail(400, 'filename contains disallowed characters');

// ---- Mint presigned PUT URL ----
try {
    $s3 = new S3Client([
        'version' => 'latest',
        'region' => $region,
        'credentials' => ['key' => $akid, 'secret' => $secret],
    ]);
    $key = $prefix . $jobId . '/' . $filename;
    $cmd = $s3->getCommand('PutObject', [
        'Bucket' => $s3bucket,
        'Key' => $key,
    ]);
    $req = $s3->createPresignedRequest($cmd, "+{$ttl} seconds");
    $uploadUrl = (string)$req->getUri();
} catch (AwsException $e) {
    fail(502, 'presign failed: ' . $e->getMessage());
} catch (\Throwable $e) {
    fail(500, 'presign failed: ' . $e->getMessage());
}

error_log(sprintf("[presign] ip=%s job=%s file=%s key=%s ttl=%d",
    $_SERVER['REMOTE_ADDR'] ?? 'unknown', $jobId, $filename, $key, $ttl));

echo json_encode([
    'uploadUrl' => $uploadUrl,
    'expiresIn' => $ttl,
    'key'       => $key,
    'method'    => 'PUT',
    'maxBytes'  => $maxBytes,
]);
