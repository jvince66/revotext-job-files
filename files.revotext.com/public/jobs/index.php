<?php
declare(strict_types=1);

// MSAL config from the shared support-portal db-config.json
$dbCfg = @json_decode((string)@file_get_contents('/var/www/html/api/db-config.json'), true);
$clientId = is_array($dbCfg) ? (string)($dbCfg['portal_msal_client_id'] ?? '') : '';
$tenantId = is_array($dbCfg) ? (string)($dbCfg['portal_msal_tenant_id'] ?? '') : '';

// Job ID validation pattern (from aws-config.json if present, else the default)
$awsCfg = @json_decode((string)@file_get_contents(__DIR__ . '/../../api/aws-config.json'), true);
$jobPattern = is_array($awsCfg) ? (string)($awsCfg['job_id_pattern'] ?? '/^C-\d{3}[A-Z0-9]{2}-\d{3}T?$/') : '/^C-\d{3}[A-Z0-9]{2}-\d{3}T?$/';

$jobId = trim((string)($_GET['job'] ?? ''));

$safeJob = htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8');
$safeClient = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
$safeTenant = htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');
$safePattern = json_encode($jobPattern);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Revotext Files<?= $safeJob !== '' ? ' — ' . $safeJob : '' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<style>
  body { font-family: -apple-system, "Segoe UI", sans-serif; max-width: 860px; margin: 40px auto; padding: 0 20px; color: #1a202c; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .sub { color: #667085; font-size: 14px; margin-bottom: 24px; }
  .job-badge { display: inline-block; padding: 3px 10px; background: #e8eef7; color: #224e99; border-radius: 100px; font: 600 12px monospace; }
  .btn { display: inline-block; padding: 10px 18px; background: #224e99; color: #fff; border: 0; border-radius: 8px; font: 600 14px sans-serif; cursor: pointer; text-decoration: none; }
  .btn:hover { background: #1a3d7a; }
  #status { margin: 16px 0; color: #667085; font-size: 14px; }
  #status.err { color: #b32136; }
  #signin-panel, #files-panel, #error-panel { display: none; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #d8dce3; }
  th { background: #f8f9fa; font: 600 12px sans-serif; color: #667085; text-transform: uppercase; letter-spacing: .04em; }
  td { font-size: 14px; }
  td.size { text-align: right; font-variant-numeric: tabular-nums; color: #667085; }
  td.name a { color: #224e99; text-decoration: none; font-weight: 500; }
  td.name a:hover { text-decoration: underline; }
  .viewer { font-size: 12px; color: #667085; margin-top: 24px; }
  .empty { padding: 40px; text-align: center; color: #667085; background: #f8f9fa; border-radius: 8px; }
</style>
</head>
<body>
<h1>Revotext Job Files</h1>
<div class="sub"><?php if ($jobId !== ''): ?>Job <span class="job-badge"><?= $safeJob ?></span><?php else: ?>Open a per-job URL like <code>files.revotext.com/jobs/C-YMMLL-NNN</code>.<?php endif; ?></div>

<div id="signin-panel">
  <p>Sign in with your Microsoft 365 account to view the files for this job.</p>
  <button class="btn" id="signin-btn">Sign in with Microsoft</button>
</div>

<div id="files-panel">
  <div id="status">Loading...</div>
  <div id="file-list"></div>
  <div class="viewer" id="viewer"></div>
</div>

<div id="error-panel">
  <p id="error-msg"></p>
</div>

<script>
const CLIENT_ID = '<?= $safeClient ?>';
const TENANT_ID = '<?= $safeTenant ?>';
const JOB_ID = '<?= $safeJob ?>';
const JOB_PATTERN = <?= $safePattern ?>;
const SCOPES = ['User.Read'];

function $(id) { return document.getElementById(id); }
function fmtSize(b) {
  if (b == null) return '';
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  if (b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
  return (b/1073741824).toFixed(1) + ' GB';
}
function fmtDate(iso) { if (!iso) return ''; try { return new Date(iso).toLocaleString(); } catch (e) { return iso; } }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showError(msg) {
  $('signin-panel').style.display = 'none';
  $('files-panel').style.display = 'none';
  $('error-panel').style.display = 'block';
  $('error-msg').textContent = msg;
}

(function () {
  if (!JOB_ID) { showError('No Job ID in URL. Use https://files.revotext.com/jobs/C-YMMLL-NNN'); return; }
  const slash = JOB_PATTERN.lastIndexOf('/');
  const re = new RegExp(JOB_PATTERN.slice(1, slash));
  if (!re.test(JOB_ID)) { showError('Invalid Job ID format: ' + JOB_ID); return; }
  if (!CLIENT_ID || !TENANT_ID) { showError('Portal is not configured on the server.'); return; }
  loadMsal();
})();

let msalInstance = null, account = null;

function loadMsal() {
  const s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/@azure/msal-browser@2.38.3/lib/msal-browser.min.js';
  // Subresource Integrity — pins CDN payload to a known SHA-384.
  // If jsdelivr is compromised or the file is tampered with, browser refuses to execute.
  // Recompute: curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
  s.integrity = 'sha384-OJTwghM0Gh3Zc+gmd6l0lz1pFw9KuHHq0mSEZgmQEKgMnuSWWDb3bU9KzuD7w2hU';
  s.crossOrigin = 'anonymous';
  s.onload = initMsal;
  s.onerror = function () { showError('Failed to load Microsoft sign-in library or SRI check failed.'); };
  document.head.appendChild(s);
}

async function initMsal() {
  msalInstance = new msal.PublicClientApplication({
    auth: {
      clientId: CLIENT_ID,
      authority: 'https://login.microsoftonline.com/' + TENANT_ID,
      redirectUri: window.location.origin + '/jobs/',
    },
    cache: { cacheLocation: 'sessionStorage' },
  });
  try {
    const resp = await msalInstance.handleRedirectPromise();
    if (resp && resp.account) { account = resp.account; msalInstance.setActiveAccount(account); afterSignIn(); return; }
  } catch (e) { /* fall through */ }
  const existing = msalInstance.getAllAccounts();
  if (existing.length > 0) { account = existing[0]; msalInstance.setActiveAccount(account); afterSignIn(); return; }
  $('signin-panel').style.display = 'block';
  $('signin-btn').addEventListener('click', function () {
    msalInstance.loginPopup({ scopes: SCOPES }).then(function (r) { account = r.account; msalInstance.setActiveAccount(account); afterSignIn(); }).catch(function (e) { showError('Sign-in failed: ' + e.message); });
  });
}

async function afterSignIn() {
  $('signin-panel').style.display = 'none';
  $('files-panel').style.display = 'block';
  $('status').textContent = 'Loading files...';
  try {
    const r = await msalInstance.acquireTokenSilent({ scopes: SCOPES, account: account });
    const resp = await fetch('/api/list-files.php?job=' + encodeURIComponent(JOB_ID), {
      headers: { 'Authorization': 'Bearer ' + r.accessToken },
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || ('HTTP ' + resp.status));
    renderFiles(data);
  } catch (e) {
    $('status').textContent = 'Failed to load: ' + e.message;
    $('status').classList.add('err');
  }
}

function renderFiles(data) {
  $('status').textContent = data.file_count + ' file' + (data.file_count===1?'':'s') + ' in ' + data.prefix;
  $('viewer').textContent = 'Signed in as ' + (data.viewer.name || data.viewer.email) + '. Download links expire in ' + Math.round(data.presign_ttl/60) + ' minutes.';
  if (!data.files || !data.files.length) {
    $('file-list').innerHTML = '<div class="empty">No files have been uploaded to this job yet.</div>';
    return;
  }
  const rows = data.files.map(function (f) {
    return '<tr>' +
      '<td class="name"><a href="' + esc(f.download_url) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a></td>' +
      '<td class="size">' + esc(fmtSize(f.size)) + '</td>' +
      '<td>' + esc(fmtDate(f.last_modified)) + '</td>' +
    '</tr>';
  }).join('');
  $('file-list').innerHTML =
    '<table><thead><tr><th>File</th><th class="size">Size</th><th>Uploaded</th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table>';
}
</script>
</body>
</html>
