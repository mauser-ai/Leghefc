<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
requireAdmin();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $code = trim((string)($_POST['invite_code'] ?? ''));
        if ($name === '' || $code === '') {
            $error = 'Nome e codice invito sono obbligatori.';
        } elseif (AuctionService::findByInviteCode($code) !== null) {
            $error = 'Codice invito già in uso.';
        } else {
            AuctionService::createAuction([
                'name' => $name,
                'invite_code' => $code,
                'auction_date' => (string)($_POST['auction_date'] ?? ''),
                'initial_budget' => (int)($_POST['initial_budget'] ?? 500),
                'goalkeepers' => (int)($_POST['goalkeepers'] ?? 3),
                'defenders' => (int)($_POST['defenders'] ?? 8),
                'midfielders' => (int)($_POST['midfielders'] ?? 8),
                'attackers' => (int)($_POST['attackers'] ?? 6),
            ]);
            $message = 'Asta creata con successo.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        AuctionService::updateAuction($id, [
            'name' => trim((string)($_POST['name'] ?? '')),
            'invite_code' => trim((string)($_POST['invite_code'] ?? '')),
            'auction_date' => (string)($_POST['auction_date'] ?? ''),
            'initial_budget' => (int)($_POST['initial_budget'] ?? 500),
            'goalkeepers' => (int)($_POST['goalkeepers'] ?? 3),
            'defenders' => (int)($_POST['defenders'] ?? 8),
            'midfielders' => (int)($_POST['midfielders'] ?? 8),
            'attackers' => (int)($_POST['attackers'] ?? 6),
        ]);
        $message = 'Asta aggiornata.';
    } elseif ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (AuctionService::setStatus($id, $status)) {
            $message = "Stato asta aggiornato a $status.";
        } else {
            $error = 'Stato non valido.';
        }
    }
}

$auctions = AuctionService::listAll();

$pageTitle = 'Aste - Admin';
$showNav = true;
require __DIR__ . '/../partials/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">🏆 Gestione Aste</h2>
    <a href="/admin/index.php" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">➕ Crea nuova asta</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create">
        <div class="col-md-4">
          <label class="form-label">Nome asta</label>
          <input type="text" name="name" class="form-control" required placeholder="Es. Asta Fantacalcio 2026">
        </div>
        <div class="col-md-2">
          <label class="form-label">Codice invito</label>
          <input type="text" name="invite_code" class="form-control text-uppercase" required placeholder="FANTA26">
        </div>
        <div class="col-md-2">
          <label class="form-label">Data asta</label>
          <input type="date" name="auction_date" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Budget</label>
          <input type="number" name="initial_budget" class="form-control" value="500" min="1">
        </div>
        <div class="col-md-2"></div>
        <div class="col-md-2">
          <label class="form-label">Portieri</label>
          <input type="number" name="goalkeepers" class="form-control" value="3" min="0">
        </div>
        <div class="col-md-2">
          <label class="form-label">Difensori</label>
          <input type="number" name="defenders" class="form-control" value="8" min="0">
        </div>
        <div class="col-md-2">
          <label class="form-label">Centrocampisti</label>
          <input type="number" name="midfielders" class="form-control" value="8" min="0">
        </div>
        <div class="col-md-2">
          <label class="form-label">Attaccanti</label>
          <input type="number" name="attackers" class="form-control" value="6" min="0">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-success">Crea asta</button>
        </div>
      </form>
    </div>
  </div>

  <?php foreach ($auctions as $a):
      $participants = TeamService::getAuctionTeams((int)$a['id']);
      $transitions = match ($a['status']) {
          Schema::STATUS_DRAFT => [Schema::STATUS_OPEN],
          Schema::STATUS_OPEN => [Schema::STATUS_LIVE, Schema::STATUS_DRAFT],
          Schema::STATUS_LIVE => [Schema::STATUS_COMPLETED],
          Schema::STATUS_COMPLETED => [Schema::STATUS_ARCHIVED, Schema::STATUS_LIVE],
          Schema::STATUS_ARCHIVED => [],
          default => [],
      };
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= e($a['name']) ?> <span class="badge status-badge-<?= e($a['status']) ?> ms-2"><?= e($a['status']) ?></span></span>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($a['status'] === Schema::STATUS_LIVE): ?>
          <a href="/admin/auction.php?id=<?= (int)$a['id'] ?>" class="btn btn-danger btn-sm pulse-live">🔴 Gestisci LIVE</a>
        <?php elseif (in_array($a['status'], [Schema::STATUS_DRAFT, Schema::STATUS_OPEN], true)): ?>
          <a href="/admin/auction.php?id=<?= (int)$a['id'] ?>" class="btn btn-outline-secondary btn-sm">Anteprima gestione</a>
        <?php else: ?>
          <a href="/admin/auction.php?id=<?= (int)$a['id'] ?>" class="btn btn-outline-secondary btn-sm">Vedi storico</a>
        <?php endif; ?>
        <a href="/display.php?auction=<?= (int)$a['id'] ?>" target="_blank" class="btn btn-outline-info btn-sm">📺 Display</a>
        <a href="/export.php?auction=<?= (int)$a['id'] ?>" class="btn btn-outline-light btn-sm">⬇️ Export</a>
      </div>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3 mb-3">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <div class="col-md-3">
          <label class="form-label small">Nome</label>
          <input type="text" name="name" class="form-control form-control-sm" value="<?= e($a['name']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Codice invito</label>
          <input type="text" name="invite_code" class="form-control form-control-sm text-uppercase" value="<?= e($a['invite_code']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Data</label>
          <input type="date" name="auction_date" class="form-control form-control-sm" value="<?= e($a['auction_date']) ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small">Budget</label>
          <input type="number" name="initial_budget" class="form-control form-control-sm" value="<?= (int)$a['initial_budget'] ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small">POR</label>
          <input type="number" name="goalkeepers" class="form-control form-control-sm" value="<?= (int)$a['goalkeepers'] ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small">DIF</label>
          <input type="number" name="defenders" class="form-control form-control-sm" value="<?= (int)$a['defenders'] ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small">CEN</label>
          <input type="number" name="midfielders" class="form-control form-control-sm" value="<?= (int)$a['midfielders'] ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label small">ATT</label>
          <input type="number" name="attackers" class="form-control form-control-sm" value="<?= (int)$a['attackers'] ?>">
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-sm btn-outline-primary">💾 Salva modifiche</button>
        </div>
      </form>

      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <strong>Partecipanti (<?= count($participants) ?>):</strong>
          <?php if (empty($participants)): ?>
            <span class="text-dim">nessuno</span>
          <?php else: ?>
            <?php foreach ($participants as $t): ?><span class="badge bg-secondary me-1"><?= e($t['name']) ?></span><?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
          <?php foreach ($transitions as $next): ?>
            <form method="post" onsubmit="return confirm('Passare lo stato a <?= e($next) ?>?');">
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <input type="hidden" name="status" value="<?= e($next) ?>">
              <button type="submit" class="btn btn-sm btn-outline-warning">→ <?= e($next) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
