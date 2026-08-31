<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$userId = Auth::userId();
$team = TeamService::getTeamByUser($userId);
if ($team === null) {
    redirect('/profile.php?first=1');
}

$error = '';
$joined = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string)($_POST['invite_code'] ?? ''));
    $auction = AuctionService::findByInviteCode($code);

    if ($auction === null) {
        $error = 'Codice invito non valido.';
    } elseif (in_array($auction['status'], [Schema::STATUS_ARCHIVED], true)) {
        $error = "Questa asta è stata archiviata e non accetta nuove squadre.";
    } else {
        AuctionService::joinAuction((int)$auction['id'], (int)$team['id']);
        $joined = $auction;
    }
}

$pageTitle = 'Entra in asta - Fantacalcio Asta';
$showNav = true;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4" style="max-width:600px;">
  <h2 class="mb-4">🔑 Entra in un'asta</h2>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <?php if ($joined): ?>
    <div class="alert alert-success">
      <h5 class="mb-2">Squadra associata con successo!</h5>
      <p class="mb-1"><strong>Asta:</strong> <?= e($joined['name']) ?></p>
      <p class="mb-1"><strong>Data:</strong> <?= e($joined['auction_date']) ?></p>
      <p class="mb-1"><strong>Budget:</strong> <?= e($joined['initial_budget']) ?> crediti</p>
      <p class="mb-1"><strong>Partecipanti:</strong> <?= count(TeamService::getAuctionTeams((int)$joined['id'])) ?></p>
      <p class="mb-0"><strong>Stato:</strong> <span class="badge status-badge-<?= e($joined['status']) ?>"><?= e($joined['status']) ?></span></p>
    </div>
    <a href="/dashboard.php" class="btn btn-primary">Vai alla dashboard</a>
  <?php else: ?>
    <div class="card">
      <div class="card-body">
        <p class="text-dim">Inserisci il codice invito fornito dall'amministratore dell'asta per associare la tua squadra "<strong><?= e($team['name']) ?></strong>".</p>
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Codice invito</label>
            <input type="text" name="invite_code" class="form-control text-uppercase" required autofocus placeholder="Es. FANTA26">
          </div>
          <button type="submit" class="btn btn-success">Entra nell'asta</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
