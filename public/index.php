<?php
declare(strict_types=1);

use App\{Util, Database, Storage, Cache};

require __DIR__ . '/../src/bootstrap.php';

$alerts = [];
$errors = [];

// Neue Installation / Schema-Meldungen anzeigen
foreach ($messages as $m) {
    $alerts[] = $m;
}

// Upload verarbeiten
if (($_POST['action'] ?? '') === 'upload') {
    Util::checkCsrf();

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Bitte eine Bilddatei auswählen.';
    } else {
        $file  = $_FILES['image']['tmp_name'];
        $name  = $_FILES['image']['name'];
        $size  = (int)$_FILES['image']['size'];
        $mime  = detect_mime($file);

        if ($size <= 0) {
            $errors[] = 'Leere Datei.';
        } elseif ($size > Util::bytesFromEnv('MAX_UPLOAD_BYTES', 10 * 1024 * 1024)) {
            $errors[] = 'Datei ist zu groß.';
        } elseif (!allowed_mime($mime)) {
            $errors[] = 'Nur JPEG, PNG, GIF oder WebP erlaubt.';
        } else {
            $ext = match ($mime) {
                'image/jpeg' => '.jpg',
                'image/png'  => '.png',
                'image/gif'  => '.gif',
                'image/webp' => '.webp',
                default => '',
            };
            $key = date('Y/m/') . bin2hex(random_bytes(16)) . $ext;
            try {
                $storage->put($key, $file, $mime);
                $db->insertImage($key, $name, $mime, $size);
                $cache->del('images:list');
                $alerts[] = 'Bild erfolgreich hochgeladen.';
            } catch (Throwable $e) {
                $errors[] = 'Upload fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Delete verarbeiten
if (($_POST['action'] ?? '') === 'delete') {
    Util::checkCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $row = $db->deleteImageById($id);
            if ($row) {
                $storage->delete($row['object_key']);
                $cache->del('images:list');
                $alerts[] = 'Bild gelöscht.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Löschen fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Liste abrufen (mit Redis-Cache)
$list = $cache->get('images:list');
if ($list !== null) {
    $alerts[] = 'Cache HIT (images:list)';
    header('X-Cache-Images-List: HIT');
} else {
    $alerts[] = 'Cache MISS (images:list) – frisch aus DB, 30s gecached.';
    header('X-Cache-Images-List: MISS');
    $list = $db->listImages();
    $cache->set('images:list', $list, 30);
}

// Presigned URLs generieren (kurz gültig)
$items = [];
foreach ($list as $row) {
    $url = $storage->presignedUrl($row['object_key']);
    $items[] = [
        'id' => (int)$row['id'],
        'url' => $url,
        'name' => $row['original_name'],
        'mime' => $row['mime_type'],
        'size' => (int)$row['size'],
        'created_at' => $row['created_at'],
    ];
}

$csrf = Util::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Image Upload (Nine S3 + DB + Redis)</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  <div class="container">
    <header>
      <div class="logo">📸 Image Uploader</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="upload">
        <label for="file">Bild hochladen:</label>
        <input type="file" name="image" id="file" accept="image/*" required>
        <input type="submit" value="Hochladen">
      </form>
    </header>

    <?php if ($alerts): ?>
      <div class="messages">
        <?php foreach ($alerts as $a): ?>
          <div>✅ <?= htmlspecialchars($a) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="messages error">
        <?php foreach ($errors as $e): ?>
          <div>⚠️ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2>Deine Bilder</h2>
    <?php if (!$items): ?>
      <p>Noch keine Bilder vorhanden.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($items as $it): ?>
          <div class="card">
            <a href="<?= htmlspecialchars($it['url']) ?>" target="_blank" rel="noopener">
              <img src="<?= htmlspecialchars($it['url']) ?>" alt="Bild">
            </a>
            <div><strong><?= htmlspecialchars($it['name']) ?></strong></div>
            <small><?= htmlspecialchars($it['mime']) ?> • <?= number_format($it['size'] / 1024, 1, ',', '.') ?> KB • <?= htmlspecialchars($it['created_at']) ?></small>
            <form class="delete" method="post" onsubmit="return confirm('Bild wirklich löschen?')">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit">Löschen</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <footer>
      <p>Speicher: Nine Object Storage (S3-kompatibel) • Register in DB • Cache via Redis • Presigned-Links (15 Min).</p>
    </footer>
  </div>
</body>
</html>
