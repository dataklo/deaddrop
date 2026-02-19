<?php
$baseDir = realpath('/var/www/deaddrop');
if ($baseDir === false) {
    $baseDir = realpath(__DIR__ . '/..');
}
if ($baseDir === false) {
    $baseDir = dirname(__DIR__);
}

$dataDir = $baseDir . '/daten';
$uploadDir = $baseDir . '/upload';
$trashDir = $dataDir . '/.trash';
$storageLimitBytes = 10 * 1024 * 1024 * 1024;
$maxUploadBytes = 50 * 1024 * 1024;
$trashRetentionSeconds = 90 * 24 * 60 * 60;
$allowedExt = [
  'jpg','jpeg','png','gif','webp','bmp','svg',
  'pdf','txt','md','rtf',
  'doc','docx','odt',
  'xls','xlsx','ods',
  'ppt','pptx','odp',
  'csv','json','xml'
];
$blockedExt = ['exe','msi','bat','cmd','ps1','scr','com','pif','jar','vbs','js','sh'];
$msg = '';

if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0733, true); }
if (!is_dir($trashDir)) { @mkdir($trashDir, 0755, true); }

function directorySize(string $path): int {
    if (!is_dir($path)) {
        return 0;
    }

    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $size += $fileInfo->getSize();
        }
    }

    return $size;
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $pow = ($size > 0) ? (int)floor(log($size, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $value = $size / (1024 ** $pow);
    return number_format($value, $pow === 0 ? 0 : 1, ',', '.') . ' ' . $units[$pow];
}

function removeDirectoryIfEmpty(string $dir, string $stopAt): void {
    $current = $dir;
    while ($current !== $stopAt && str_starts_with($current, $stopAt)) {
        if (!is_dir($current)) {
            break;
        }
        $entries = @scandir($current) ?: [];
        if (count($entries) > 2) {
            break;
        }
        @rmdir($current);
        $current = dirname($current);
    }
}

function deleteFileOrDir(string $path): void {
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = @scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        deleteFileOrDir($path . '/' . $item);
    }
    @rmdir($path);
}

function purgeExpiredTrash(string $trashDir, int $retentionSeconds): void {
    if (!is_dir($trashDir)) {
        return;
    }

    $threshold = time() - $retentionSeconds;
    $entries = @scandir($trashDir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $trashDir . '/' . $entry;
        $mtime = @filemtime($path);
        if ($mtime === false) {
            continue;
        }
        if ($mtime <= $threshold) {
            deleteFileOrDir($path);
        }
    }
}

function sendTelegramUploadNotification(string $filename, string $folder): void {
    $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    $chatId = getenv('TELEGRAM_CHAT_ID') ?: '';
    if ($token === '' || $chatId === '') {
        return;
    }

    $message = "📤 Neuer Upload\nDatei: {$filename}\nPfad: /daten/{$folder}/";
    $query = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
    ]);
    $url = "https://api.telegram.org/bot{$token}/sendMessage?{$query}";

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
        ]
    ]);
    @file_get_contents($url, false, $ctx);
}

purgeExpiredTrash($trashDir, $trashRetentionSeconds);
$usedBytes = directorySize($dataDir) + directorySize($uploadDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_path'])) {
    $deletePath = trim((string)$_POST['delete_path']);
    if ($deletePath === '' || strpos($deletePath, '..') !== false) {
        $msg = 'Ungültiger Löschpfad.';
    } else {
        $target = $dataDir . '/' . $deletePath;
        if (!is_file($target)) {
            $msg = 'Datei wurde nicht gefunden.';
        } else {
            $trashName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . basename($deletePath);
            $trashTarget = $trashDir . '/' . $trashName;
            if (!@rename($target, $trashTarget)) {
                $msg = 'Datei konnte nicht gelöscht werden.';
            } else {
                removeDirectoryIfEmpty(dirname($target), $dataDir);
                $msg = 'Datei wurde gelöscht.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $errorCode = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'Upload fehlgeschlagen: Maximale Dateigröße ist 50 MB.';
    } elseif ($errorCode === UPLOAD_ERR_PARTIAL) {
        $msg = 'Upload unvollständig. Bitte erneut versuchen.';
    } elseif ($errorCode === UPLOAD_ERR_NO_FILE) {
        $msg = 'Bitte eine Datei auswählen.';
    } elseif ($errorCode !== UPLOAD_ERR_OK) {
        $msg = 'Upload fehlgeschlagen. Bitte später erneut versuchen.';
    } else {
        $origName = basename((string)$_FILES['file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileSize = (int)$_FILES['file']['size'];

        if ($fileSize > $maxUploadBytes) {
            $msg = 'Datei zu groß. Maximal erlaubt sind 50 MB.';
        } elseif (in_array($ext, $blockedExt, true) || !in_array($ext, $allowedExt, true)) {
            $msg = 'Dateityp nicht erlaubt.';
        } elseif ($usedBytes + $fileSize > $storageLimitBytes) {
            $msg = 'Speicherlimit von 10 GB erreicht. Upload nicht möglich.';
        } else {
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            $staged = $uploadDir . '/' . uniqid('up_', true) . '_' . $safeName;
            $dateDirName = date('Y-m-d');
            $dateDir = $dataDir . '/' . $dateDirName;

            if (!is_dir($dateDir) && !@mkdir($dateDir, 0755, true)) {
                $msg = 'Tagesordner konnte nicht erstellt werden.';
            } elseif (!is_writable($uploadDir)) {
                $msg = 'Upload-Verzeichnis nicht beschreibbar.';
            } elseif (!move_uploaded_file($_FILES['file']['tmp_name'], $staged)) {
                $msg = 'Upload fehlgeschlagen. Bitte später erneut versuchen.';
            } else {
                $scanCmd = 'clamscan --no-summary ' . escapeshellarg($staged) . ' >/dev/null 2>&1';
                exec($scanCmd, $o, $rc);

                if ($rc === 0) {
                    $target = $dateDir . '/' . $safeName;
                    if (is_file($target)) {
                        @unlink($target);
                    }
                    if (!@rename($staged, $target)) {
                        @unlink($staged);
                        $msg = 'Datei konnte nicht übernommen werden.';
                    } else {
                        sendTelegramUploadNotification($safeName, $dateDirName);
                        $msg = 'Upload erfolgreich. Datei ist jetzt im Archiv sichtbar.';
                    }
                } else {
                    @unlink($staged);
                    $msg = 'Upload wurde aus Sicherheitsgründen abgelehnt.';
                }
            }
        }
    }
}

$entries = [];
$days = [];
if (is_dir($dataDir)) {
    foreach (scandir($dataDir) as $dayFolder) {
        if ($dayFolder === '.' || $dayFolder === '..' || $dayFolder[0] === '.') continue;
        $dayPath = $dataDir . '/' . $dayFolder;
        if (!is_dir($dayPath)) continue;

        foreach (scandir($dayPath) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dayPath . '/' . $f;
            if (!is_file($p)) continue;

            $entries[] = [
              'name' => $f,
              'folder' => $dayFolder,
              'path' => $dayFolder . '/' . $f,
              'size' => filesize($p),
              'sizeHuman' => formatBytes((int)filesize($p)),
              'mtime' => filemtime($p)
            ];
            $days[$dayFolder] = true;
        }
    }
}

$usedBytes = directorySize($dataDir) + directorySize($uploadDir);
$freeBytes = max(0, $storageLimitBytes - $usedBytes);
$freePercent = ($storageLimitBytes > 0) ? ($freeBytes / $storageLimitBytes) * 100 : 0;
$usedPercent = 100 - $freePercent;
$storageClass = 'storage-fill-ok';
if ($freePercent < 5) {
    $storageClass = 'storage-fill-critical';
} elseif ($freePercent < 15) {
    $storageClass = 'storage-fill-warn';
}

$selectedDay = isset($_GET['day']) ? trim((string)$_GET['day']) : '';
if ($selectedDay !== '' && isset($days[$selectedDay])) {
    $entries = array_values(array_filter($entries, static function ($entry) use ($selectedDay) {
        return $entry['folder'] === $selectedDay;
    }));
}

$dayOptions = array_keys($days);
rsort($dayOptions);
usort($entries, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$msgClass = 'notice';
if (str_starts_with($msg, 'Upload erfolgreich')) {
    $msgClass .= ' notice-success';
}
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>WebFTP Root</title>
  <link rel="stylesheet" href="/styles.css" />
  <style>
    .topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}
    .btn-upload{background:#b10000;color:#fff;padding:.6rem .9rem;border-radius:4px;border:0;cursor:pointer}
    .btn-delete{background:#333;color:#fff;padding:.35rem .6rem;border-radius:4px;border:0;cursor:pointer;font-size:.85rem}
    .path{font-family:monospace;color:#bbb}
    .file-row{display:flex;gap:.75rem;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .filter-form{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .storage{font-size:.95rem;color:#ddd;margin-top:.35rem;max-width:460px}
    .storage-meta{display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
    .storage-bar{width:100%;height:.7rem;background:#2a2a2a;border-radius:999px;overflow:hidden;margin-top:.4rem}
    .storage-fill-ok{height:100%;background:#4fb86f}
    .storage-fill-warn{height:100%;background:#cc9f3f}
    .storage-fill-critical{height:100%;background:#c44747}
    .notice-success{border-color:#2f7d43;background:#12351d}
    .upload-form{min-width:280px}
    .upload-controls{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .btn-upload-progress{display:none;margin-top:.6rem}
    .btn-upload-progress progress{width:100%}
  </style>
</head>
<body>
<main class="container">
  <section class="hero topbar">
    <div>
      <h1>WebFTP Root</h1>
      <div class="path">/ (Root) → /daten</div>
      <div class="storage">
        <div><?= number_format($freePercent, 2, ',', '.') ?>% frei von 10GB</div>
        <div class="storage-meta">
          <span>Belegt: <?= htmlspecialchars(formatBytes((int)$usedBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span>Frei: <?= htmlspecialchars(formatBytes((int)$freeBytes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="storage-bar"><div class="<?= htmlspecialchars($storageClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" style="width: <?= htmlspecialchars((string)max(0, min(100, $usedPercent)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>%"></div></div>
      </div>
      <p><a href="/" class="path">&larr; Zurück zur Startseite</a></p>
    </div>
    <form method="post" enctype="multipart/form-data" id="uploadForm" class="upload-form">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$maxUploadBytes ?>">
      <div class="upload-controls">
        <input type="file" name="file" id="fileInput" required>
        <button type="submit" class="btn-upload">Upload</button>
      </div>
      <div class="path" style="margin-top:.4rem">maximale dateigröße 50 MB</div>
      <div class="btn-upload-progress" id="uploadProgressWrap">
        <progress id="uploadProgress" value="0" max="100"></progress>
        <div id="uploadProgressText" class="path">Upload startet…</div>
      </div>
    </form>
  </section>

  <?php if ($msg): ?><section class="<?= htmlspecialchars($msgClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><p><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></section><?php endif; ?>

  <section class="card">
    <h2>Dateien in /daten/JJJJ-MM-TT</h2>
    <form method="get" class="filter-form">
      <label for="day">Tag auswählen:</label>
      <select id="day" name="day" onchange="this.form.submit()">
        <option value="">Alle Tage</option>
        <?php foreach ($dayOptions as $day): ?>
          <option value="<?= htmlspecialchars($day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $selectedDay === $day ? ' selected' : '' ?>>
            <?= htmlspecialchars($day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Filtern</button></noscript>
    </form>

    <?php if (count($entries) === 0): ?>
      <p>Keine sichtbaren Dateien für die Auswahl gefunden.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($entries as $e): ?>
          <li class="file-row">
            <div>
              <a href="/daten/<?= rawurlencode($e['path']) ?>"><?= htmlspecialchars($e['folder'] . '/' . $e['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <small>(<?= htmlspecialchars($e['sizeHuman'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</small>
            </div>
            <form method="post" onsubmit="return confirm('Datei wirklich löschen?');">
              <input type="hidden" name="delete_path" value="<?= htmlspecialchars($e['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <button type="submit" class="btn-delete">Löschen</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
<script>
(() => {
  const form = document.getElementById('uploadForm');
  const progressWrap = document.getElementById('uploadProgressWrap');
  const progressBar = document.getElementById('uploadProgress');
  const progressText = document.getElementById('uploadProgressText');
  if (!form || !progressWrap || !progressBar || !progressText) return;

  form.addEventListener('submit', (event) => {
    const fileInput = document.getElementById('fileInput');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      return;
    }

    event.preventDefault();
    const xhr = new XMLHttpRequest();
    const formData = new FormData(form);

    progressWrap.style.display = 'block';
    progressBar.value = 0;
    progressText.textContent = 'Upload läuft… 0%';

    xhr.upload.addEventListener('progress', (e) => {
      if (!e.lengthComputable) return;
      const percent = Math.round((e.loaded / e.total) * 100);
      progressBar.value = percent;
      progressText.textContent = `Upload läuft… ${percent}%`;
    });

    xhr.addEventListener('load', () => {
      progressText.textContent = 'Upload abgeschlossen. Seite wird aktualisiert…';
      window.location.reload();
    });

    xhr.addEventListener('error', () => {
      progressText.textContent = 'Upload fehlgeschlagen. Bitte erneut versuchen.';
    });

    xhr.open('POST', window.location.href);
    xhr.send(formData);
  });
})();
</script>
</body>
</html>
