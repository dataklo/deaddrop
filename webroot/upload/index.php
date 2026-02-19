<?php
$uploadDir = __DIR__;
$maxSize = 1024 * 1024 * 1024; // 1GB
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Upload-Fehler: ' . $_FILES['file']['error'];
    } elseif ($_FILES['file']['size'] > $maxSize) {
        $msg = 'Datei ist zu groß (max 1GB).';
    } else {
        $name = basename($_FILES['file']['name']);
        $target = $uploadDir . DIRECTORY_SEPARATOR . $name;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            $msg = 'Upload erfolgreich: ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $msg = 'Upload fehlgeschlagen.';
        }
    }
}

$files = array_filter(scandir($uploadDir), function ($f) {
    return $f !== '.' && $f !== '..' && $f !== 'index.php';
});
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>WebFTP Upload</title>
  <link rel="stylesheet" href="/styles.css" />
</head>
<body>
  <main class="container">
    <section class="hero">
      <h1>WebFTP / Upload</h1>
      <p>Öffentlicher Upload-Bereich des Wireless DeadDrop.</p>
      <p><a href="/disclaimer.html">Haftungsausschluss</a> · <a href="/">Startseite</a></p>
    </section>

    <?php if ($msg): ?>
      <section class="notice"><p><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></section>
    <?php endif; ?>

    <section class="card">
      <form method="post" enctype="multipart/form-data">
        <label>Datei auswählen: <input type="file" name="file" required></label>
        <button type="submit">Hochladen</button>
      </form>
    </section>

    <section class="card">
      <h2>Dateien (öffentlich sichtbar)</h2>
      <ul>
        <?php foreach ($files as $f): ?>
          <li><a href="<?= rawurlencode($f) ?>"><?= htmlspecialchars($f, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </main>
</body>
</html>
