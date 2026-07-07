<?php
$checks = [];
$checks['PHP Version'] = ['required' => '8.2+', 'current' => phpversion(), 'ok' => version_compare(phpversion(), '8.2.0', '>=')];

$exts = ['curl', 'gd', 'intl', 'json', 'openssl', 'pdo', 'pdo_mysql', 'mbstring', 'xml', 'zip'];
foreach ($exts as $ext) {
    $checks["ext-{$ext}"] = ['required' => 'enabled', 'current' => extension_loaded($ext) ? 'enabled' : 'missing', 'ok' => extension_loaded($ext)];
}

$checks['mod_rewrite'] = ['required' => 'enabled', 'current' => function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules()) ? 'enabled' : 'disabled') : 'unknown (not Apache CLI)', 'ok' => true];

$parentDir = dirname(__DIR__);
$checks['var/ writable'] = ['required' => 'writable', 'current' => is_writable($parentDir . '/var') ? 'writable' : 'not writable', 'ok' => is_writable($parentDir . '/var')];
$checks['vendor/ exists'] = ['required' => 'exists', 'current' => is_dir($parentDir . '/vendor') ? 'exists' : 'missing', 'ok' => is_dir($parentDir . '/vendor')];

$allOk = true;
foreach ($checks as $c) { if (!$c['ok']) $allOk = false; }
?>
<!DOCTYPE html>
<html><head><title>SolidInvoice Setup Check</title>
<style>body{font-family:system-ui;max-width:600px;margin:40px auto;padding:20px}table{width:100%;border-collapse:collapse}td,th{padding:8px 12px;border:1px solid #e2e8f0;text-align:left}.ok{color:#16a34a}.fail{color:#dc2626}h1{color:#1e293b}.status{font-size:1.2em;padding:15px;border-radius:8px;margin:20px 0}.pass{background:#dcfce7;color:#166534}.fail-bg{background:#fef2f2;color:#991b1b}</style></head>
<body>
<h1>Setup Check</h1>
<div class="status <?= $allOk ? 'pass' : 'fail-bg' ?>">
    <?= $allOk ? 'All checks passed! You can proceed with installation.' : 'Some checks failed. Please fix the issues below.' ?>
</div>
<table>
<tr><th>Check</th><th>Required</th><th>Current</th><th>Status</th></tr>
<?php foreach ($checks as $name => $c): ?>
<tr>
    <td><strong><?= $name ?></strong></td>
    <td><?= $c['required'] ?></td>
    <td><?= $c['current'] ?></td>
    <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? 'PASS' : 'FAIL' ?></td>
</tr>
<?php endforeach; ?>
</table>
<p style="margin-top:20px;color:#64748b">Delete this file after setup is complete.</p>
</body></html>
