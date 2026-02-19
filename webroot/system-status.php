<?php
$baseDir = realpath('/var/www/deaddrop');
if ($baseDir === false) {
    $baseDir = realpath(__DIR__);
}
if ($baseDir === false) {
    $baseDir = dirname(__DIR__);
}

$dataDir = $baseDir . '/daten';
$uploadDir = $baseDir . '/upload';
$storageLimitBytes = 10 * 1024 * 1024 * 1024;
$maxUploadBytes = 50 * 1024 * 1024;

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $pow = ($size > 0) ? (int)floor(log($size, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $value = $size / (1024 ** $pow);
    return number_format($value, $pow === 0 ? 0 : 1, ',', '.') . ' ' . $units[$pow];
}

function directorySize(string $path): int {
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        if ($fileInfo->isFile()) {
            $size += $fileInfo->getSize();
        }
    }
    return $size;
}

$usedBytes = directorySize($dataDir) + directorySize($uploadDir);
$freeBytes = max(0, $storageLimitBytes - $usedBytes);
$clamPath = trim((string)shell_exec('command -v clamscan 2>/dev/null'));
$clamVersion = $clamPath !== '' ? trim((string)shell_exec('clamscan --version 2>/dev/null')) : 'nicht installiert';
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
$uptime = @file_get_contents('/proc/uptime');
$uptimeText = 'unbekannt';
if (is_string($uptime) && $uptime !== '') {
    $seconds = (int)floatval(explode(' ', trim($uptime))[0]);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    $uptimeText = "{$days}d {$hours}h {$mins}m";
}
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Systemstatus</title>
  <link rel="stylesheet" href="/styles.css" />
</head>
<body>
<main class="container">
  <section class="hero">
    <h1>Systemstatus</h1>
    <p class="muted">Interne Übersichtsseite</p>
  </section>

  <section class="card">
    <h2>Speicher</h2>
    <ul>
      <li>Gesamtkontingent: <?= htmlspecialchars(formatBytes($storageLimitBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Belegt: <?= htmlspecialchars(formatBytes($usedBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Frei: <?= htmlspecialchars(formatBytes($freeBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Upload-Limit pro Datei: <?= htmlspecialchars(formatBytes($maxUploadBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
    </ul>
  </section>

  <section class="card">
    <h2>Laufzeit</h2>
    <ul>
      <li>PHP-Version: <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Server-Uptime: <?= htmlspecialchars($uptimeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Load (1/5/15): <?= htmlspecialchars(implode(' / ', array_map(static fn($v) => number_format((float)$v, 2, ',', '.'), $load)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
      <li>Virenscan: <?= htmlspecialchars($clamVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
    </ul>
  </section>
</main>
</body>
</html>
