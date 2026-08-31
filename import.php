<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAdmin();

$TMP_DIR = DATA_DIR . '/tmp_import';
if (!is_dir($TMP_DIR)) {
    mkdir($TMP_DIR, 0775, true);
}
$ALLOWED_EXT = ['csv', 'txt', 'xlsx'];

$error = '';
$success = '';
$preview = null; // ['token'=>, 'ext'=>, 'headers'=>, 'rows'=>, 'suggested_map'=>]

function safeToken(string $token): bool
{
    return (bool)preg_match('/^[a-f0-9]{16,32}$/', $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload') {
        if (empty($_FILES['listone']) || $_FILES['listone']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Errore durante il caricamento del file.';
        } else {
            $originalName = basename($_FILES['listone']['name']);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED_EXT, true)) {
                $error = 'Formato non supportato. Usa CSV o XLSX.';
            } else {
                $token = bin2hex(random_bytes(12));
                $storedPath = $TMP_DIR . '/' . $token . '.' . $ext; // token generato server-side: nessun path traversal
                if (!move_uploaded_file($_FILES['listone']['tmp_name'], $storedPath)) {
                    $error = 'Impossibile salvare il file caricato.';
                } else {
                    try {
                        $parsed = ImportService::readUploadedFile($storedPath, $originalName);
                        $preview = [
                            'token' => $token,
                            'ext' => $ext,
                            'headers' => $parsed['headers'],
                            'rows' => array_slice($parsed['rows'], 0, 8),
                            'total_rows' => count($parsed['rows']),
                            'suggested_map' => $parsed['suggested_map'],
                        ];
                    } catch (RuntimeException $ex) {
                        $error = $ex->getMessage();
                        @unlink($storedPath);
                    }
                }
            }
        }
    } elseif ($action === 'confirm') {
        $token = (string)($_POST['token'] ?? '');
        $ext = (string)($_POST['ext'] ?? '');
        if (!safeToken($token) || !in_array($ext, $ALLOWED_EXT, true)) {
            $error = 'Richiesta non valida.';
        } else {
            $storedPath = $TMP_DIR . '/' . $token . '.' . $ext;
            $realTmp = realpath($storedPath);
            $realDir = realpath($TMP_DIR);
            if ($realTmp === false || $realDir === false || !str_starts_with($realTmp, $realDir)) {
                $error = 'File non trovato o non valido.';
            } else {
                try {
                    $originalNameFake = 'listone.' . $ext;
                    $parsed = ImportService::readUploadedFile($realTmp, $originalNameFake);
                    $columnMap = $_POST['map'] ?? [];
                    $columnMap = is_array($columnMap) ? array_map('strval', $columnMap) : [];
                    $players = ImportService::applyMapping($parsed['rows'], $columnMap);

                    if (empty($players)) {
                        $error = 'Nessun giocatore valido trovato con la mappatura selezionata (verifica il campo "Nome").';
                    } else {
                        $count = ImportService::importPlayers($players);
                        $success = "Importati $count giocatori nel listone globale.";
                        @unlink($realTmp);
                    }
                } catch (RuntimeException $ex) {
                    $error = $ex->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Import Listone - Admin';
$showNav = true;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4" style="max-width:900px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">📥 Import Listone Giocatori</h2>
    <a href="<?= url('/admin/index.php') ?>" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

  <?php if ($preview === null): ?>
    <div class="card">
      <div class="card-body">
        <p class="text-dim">Carica un file CSV o XLSX contenente il listone giocatori. Nel passo successivo potrai
          mappare manualmente le colonne del file (es. "Nome", "Squadra", "R", "Qt.A", "FVM") sui campi richiesti
          dal sistema, indipendentemente dall'ordine delle colonne.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload">
          <div class="mb-3">
            <input type="file" name="listone" class="form-control" accept=".csv,.txt,.xlsx" required>
          </div>
          <button type="submit" class="btn btn-primary">Carica e analizza</button>
        </form>
        <p class="text-dim small mt-3 mb-0">Nota: l'import XLSX richiede la libreria opzionale PhpSpreadsheet (composer). Se non installata, usa il formato CSV.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header">🧩 Mappatura colonne (<?= (int)$preview['total_rows'] ?> righe rilevate)</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="confirm">
          <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
          <input type="hidden" name="ext" value="<?= e($preview['ext']) ?>">

          <table class="table table-sm">
            <thead><tr><th>Colonna file</th><th>Campo sistema</th></tr></thead>
            <tbody>
              <?php foreach ($preview['headers'] as $h): ?>
                <tr>
                  <td><code><?= e($h) ?></code></td>
                  <td>
                    <select name="map[<?= e($h) ?>]" class="form-select form-select-sm">
                      <option value="">-- ignora --</option>
                      <?php foreach (ImportService::TARGET_FIELDS as $field): ?>
                        <option value="<?= e($field) ?>" <?= ($preview['suggested_map'][$h] ?? '') === $field ? 'selected' : '' ?>>
                          <?= e($field) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <h6 class="mt-4">Anteprima prime righe</h6>
          <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered">
              <thead><tr><?php foreach ($preview['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                  <tr><?php foreach ($preview['headers'] as $h): ?><td><?= e((string)($row[$h] ?? '')) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="alert alert-warning py-2">
            ⚠️ L'import <strong>sostituisce interamente</strong> il listone giocatori globale. Le disponibilità per le aste esistenti verranno aggiornate automaticamente.
          </div>

          <button type="submit" class="btn btn-success">Conferma import</button>
          <a href="<?= url('/import.php') ?>" class="btn btn-outline-secondary">Annulla</a>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
