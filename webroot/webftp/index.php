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
$softDeleteFile = $dataDir . '/.hidden-files.json';
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

function loadSoftDeleted(string $softDeleteFile): array {
    if (!is_file($softDeleteFile)) {
        return [];
    }
    $raw = @file_get_contents($softDeleteFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $item) {
        if (is_string($item) && $item !== '') {
            $normalized[$item] = true;
        }
    }
    return $normalized;
}

function saveSoftDeleted(string $softDeleteFile, array $deletedMap): bool {
    $list = array_keys($deletedMap);
    sort($list);
    return @file_put_contents($softDeleteFile, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
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

$softDeleted = loadSoftDeleted($softDeleteFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_path'])) {
    $deletePath = trim((string)$_POST['delete_path']);
    if ($deletePath === '' || strpos($deletePath, '..') !== false) {
        $msg = 'Ungültiger Löschpfad.';
    } else {
        $softDeleted[$deletePath] = true;
        if (saveSoftDeleted($softDeleteFile, $softDeleted)) {
            $msg = 'Datei wurde ausgeblendet (soft gelöscht).';
        } else {
            $msg = 'Datei konnte nicht ausgeblendet werden.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if ($_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (in_array($ext, $blockedExt, true) || !in_array($ext, $allowedExt, true)) {
            $msg = 'Dateityp nicht erlaubt.';
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
                    $relativePath = $dateDirName . '/' . $safeName;
                    if (is_file($target)) {
                        @unlink($target);
                    }
                    if (!@rename($staged, $target)) {
                        @unlink($staged);
                        $msg = 'Datei konnte nicht übernommen werden.';
                    } else {
                        unset($softDeleted[$relativePath]);
                        saveSoftDeleted($softDeleteFile, $softDeleted);
                        sendTelegramUploadNotification($safeName, $dateDirName);
                        $msg = 'Upload erfolgreich. Gespeichert unter /daten/' . $dateDirName . '/';
                    }
                } else {
                    @unlink($staged);
                    $msg = 'Upload verarbeitet.';
                }
            }
        }
    } else {
        $msg = 'Upload-Fehler.';
    }
}

$entries = [];
$days = [];
if (is_dir($dataDir)) {
    foreach (scandir($dataDir) as $dayFolder) {
        if ($dayFolder === '.' || $dayFolder === '..' || $dayFolder[0] === '.') continue;
        $dayPath = $dataDir . '/' . $dayFolder;
        if (!is_dir($dayPath)) continue;

        $days[$dayFolder] = true;
        foreach (scandir($dayPath) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dayPath . '/' . $f;
            if (!is_file($p)) continue;

            $relativePath = $dayFolder . '/' . $f;
            if (isset($softDeleted[$relativePath])) continue;

            $entries[] = [
              'name' => $f,
              'folder' => $dayFolder,
              'path' => $relativePath,
              'size' => filesize($p),
              'mtime' => filemtime($p)
            ];
        }
    }
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
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>WebFTP Root</title>
  <link rel="stylesheet" href="/styles.css" />
  <style>
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
    .btn-upload{background:#b10000;color:#fff;padding:.6rem .9rem;border-radius:4px;border:0;cursor:pointer}
    .btn-delete{background:#333;color:#fff;padding:.35rem .6rem;border-radius:4px;border:0;cursor:pointer;font-size:.85rem}
    .path{font-family:monospace;color:#bbb}
    .file-row{display:flex;gap:.75rem;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .filter-form{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
  </style>
</head>
<body>
<main class="container">
  <section class="hero topbar">
    <div>
      <h1>WebFTP Root</h1>
      <div class="path">/ (Root) → /daten</div>
      <p><a href="/" class="path">&larr; Zurück zur Startseite</a></p>
    </div>
    <form method="post" enctype="multipart/form-data">
      <label class="btn-upload">
        Upload
        <input type="file" name="file" style="display:none" onchange="this.form.submit()" required>
      </label>
    </form>
  </section>

  <?php if ($msg): ?><section class="notice"><p><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></section><?php endif; ?>

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
              <small>(<?= (int)$e['size'] ?> bytes)</small>
            </div>
            <form method="post" onsubmit="return confirm('Datei wirklich löschen? (wird nur ausgeblendet)');">
              <input type="hidden" name="delete_path" value="<?= htmlspecialchars($e['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <button type="submit" class="btn-delete">Löschen</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
