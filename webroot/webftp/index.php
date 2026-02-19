<?php
$baseDir = realpath('/var/www/deaddrop');
$dataDir = $baseDir . '/daten';
$uploadDir = $baseDir . '/upload';
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
                // AV scan: suspicious files are removed silently.
                $scanCmd = 'clamscan --no-summary ' . escapeshellarg($staged) . ' >/dev/null 2>&1';
                exec($scanCmd, $o, $rc);

                if ($rc === 0) {
                    $target = $dateDir . '/' . $safeName;
                    if (file_exists($target)) {
                        $target = $dateDir . '/' . pathinfo($safeName, PATHINFO_FILENAME) . '_' . time() . '.' . $ext;
                    }
                    if (!@rename($staged, $target)) {
                        @unlink($staged);
                        $msg = 'Datei konnte nicht übernommen werden.';
                    } else {
                        $msg = 'Upload erfolgreich. Gespeichert unter /daten/' . $dateDirName . '/';
                    }
                } else {
                    @unlink($staged); // no comment per requirement
                    $msg = 'Upload verarbeitet.';
                }
            }
        }
    } else {
        $msg = 'Upload-Fehler.';
    }
}

$entries = [];
if (is_dir($dataDir)) {
    foreach (scandir($dataDir) as $dayFolder) {
        if ($dayFolder === '.' || $dayFolder === '..') continue;
        $dayPath = $dataDir . '/' . $dayFolder;
        if (!is_dir($dayPath)) continue;
        foreach (scandir($dayPath) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dayPath . '/' . $f;
            if (is_file($p)) {
                $entries[] = [
                  'name' => $f,
                  'folder' => $dayFolder,
                  'path' => $dayFolder . '/' . $f,
                  'size' => filesize($p),
                  'mtime' => filemtime($p)
                ];
            }
        }
    }
}
usort($entries, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>WebFTP Root</title>
  <link rel="stylesheet" href="/styles.css" />
  <style>
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:1rem}
    .btn-upload{background:#b10000;color:#fff;padding:.6rem .9rem;border-radius:4px;border:0;cursor:pointer}
    .path{font-family:monospace;color:#bbb}
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
    <ul>
      <?php foreach ($entries as $e): ?>
        <li>
          <a href="/daten/<?= rawurlencode($e['path']) ?>"><?= htmlspecialchars($e['folder'] . '/' . $e['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <small>(<?= (int)$e['size'] ?> bytes)</small>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</main>
</body>
</html>
