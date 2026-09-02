<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$userId = Auth::userId();
$team = TeamService::getTeamByUser($userId);
if ($team === null) {
    redirect('/profile.php?first=1');
}
$teamId = (int)$team['id'];

$links = TeamService::getTeamAuctionLinks($teamId);
$auctions = [];
foreach ($links as $link) {
    $a = AuctionService::findById((int)$link['auction_id']);
    if ($a !== null) {
        $auctions[] = $a;
    }
}

// Sceglie l'asta da mostrare: quella richiesta via query, altrimenti priorità LIVE > OPEN > DRAFT > altro.
$selectedId = isset($_GET['auction']) ? (int)$_GET['auction'] : null;
$auction = null;
if ($selectedId !== null) {
    foreach ($auctions as $a) {
        if ((int)$a['id'] === $selectedId) { $auction = $a; break; }
    }
}
if ($auction === null && !empty($auctions)) {
    $priority = [Schema::STATUS_LIVE => 0, Schema::STATUS_OPEN => 1, Schema::STATUS_DRAFT => 2, Schema::STATUS_COMPLETED => 3, Schema::STATUS_ARCHIVED => 4];
    usort($auctions, fn($a, $b) => ($priority[$a['status']] ?? 9) <=> ($priority[$b['status']] ?? 9));
    $auction = $auctions[0];
}

$otherTeams = $auction !== null ? TeamService::getAuctionTeams((int)$auction['id']) : [];
$remainingBudget = $auction !== null ? AuctionService::getRemainingBudget($auction, $teamId) : null;
$isLive = $auction !== null && $auction['status'] === Schema::STATUS_LIVE;
$realTeams = $isLive ? PlayerService::realTeams() : [];

$pageTitle = 'Dashboard - Fantacalcio Asta';
$showNav = true;
require __DIR__ . '/partials/header.php';
?>
<div class="container-fluid py-3" style="max-width:900px;">
  <h3 class="mb-1">Ciao, <?= e(Auth::nickname()) ?> 👋</h3>
  <p class="text-dim mb-4">La tua squadra: <strong><?= e($team['name']) ?></strong> <a href="<?= url('/profile.php') ?>" class="small">(modifica)</a></p>

  <?php if (count($auctions) > 1): ?>
    <form method="get" class="mb-3">
      <label class="form-label small text-dim">Asta</label>
      <select name="auction" class="form-select" onchange="this.form.submit()">
        <?php foreach ($auctions as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$auction['id'] ? 'selected' : '' ?>>
            <?= e($a['name']) ?> (<?= e($a['status']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <?php if ($auction === null): ?>
    <div class="alert alert-info">
      Non sei ancora associato a nessuna asta. <a href="<?= url('/join-auction.php') ?>">Inserisci un codice invito</a> per iniziare.
    </div>
  <?php else: ?>

    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <h4 class="mb-1"><?= e($auction['name']) ?></h4>
            <div class="text-dim">Data asta: <?= e($auction['auction_date'] ?: '-') ?></div>
          </div>
          <span class="badge status-badge-<?= e($auction['status']) ?> fs-6"><?= e($auction['status']) ?></span>
        </div>
        <hr>
        <div class="row text-center">
          <div class="col-6 col-md-3">
            <div class="text-dim small">Crediti iniziali</div>
            <div class="credit-medium"><?= (int)$auction['initial_budget'] ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-dim small">Crediti residui</div>
            <div class="credit-medium credit-positive" id="remainingBudget"><?= (int)$remainingBudget ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-dim small">Partecipanti</div>
            <div class="credit-medium"><?= count($otherTeams) ?></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="text-dim small">Rosa (POR/DIF/CEN/ATT)</div>
            <div class="fw-bold"><?= (int)$auction['goalkeepers'] ?>/<?= (int)$auction['defenders'] ?>/<?= (int)$auction['midfielders'] ?>/<?= (int)$auction['attackers'] ?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$isLive): ?>
      <div class="alert alert-warning text-center fw-bold">
        ⏳ L'asta non è ancora iniziata
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-md-<?= $isLive ? '7' : '12' ?>">
        <div class="card">
          <div class="card-header">Partecipanti</div>
          <ul class="list-group list-group-flush">
            <?php foreach ($otherTeams as $t): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center <?= (int)$t['id'] === $teamId ? 'bg-body-tertiary' : '' ?>">
                <span><?= e($t['name']) ?> <?= (int)$t['id'] === $teamId ? '<span class="badge bg-primary ms-1">Tu</span>' : '' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <?php if ($isLive): ?>
      <div class="col-md-5">
        <div class="card mb-3" id="currentPlayerCard">
          <div class="card-header">🎯 Giocatore all'asta</div>
          <div class="card-body" id="currentPlayerBody">
            <p class="text-dim mb-0">Nessun giocatore selezionato al momento.</p>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($isLive): ?>
    <div class="card mt-3" id="buyPlayerCard">
      <div class="card-header">🛒 Compra un giocatore <span class="text-dim fw-normal small">— tocca per dichiarare l'acquisto</span></div>
      <div class="card-body">
        <input type="text" id="buySearchQuery" class="form-control mb-2" placeholder="Cerca per nome...">
        <div class="row g-2 mb-2">
          <div class="col-4">
            <select id="buyFilterRole" class="form-select form-select-sm">
              <option value="">Tutti i ruoli</option>
              <option value="P">Portieri</option>
              <option value="D">Difensori</option>
              <option value="C">Centrocampisti</option>
              <option value="A">Attaccanti</option>
            </select>
          </div>
          <div class="col-4">
            <select id="buyFilterTeam" class="form-select form-select-sm">
              <option value="">Tutte le squadre</option>
              <?php foreach ($realTeams as $rt): ?>
                <option value="<?= e($rt) ?>"><?= e($rt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <select id="buyFilterSort" class="form-select form-select-sm">
              <option value="name">Ordina: Nome</option>
              <option value="role">Ordina: Ruolo</option>
              <option value="quotation">Ordina: Quotazione</option>
              <option value="fvm">Ordina: Fantamedia</option>
            </select>
          </div>
        </div>
        <div id="buyPlayerResults" style="max-height:340px; overflow-y:auto;"></div>
      </div>
    </div>

    <div class="card mt-3" id="liveRosterCard">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>📋 La tua rosa</span>
        <span class="text-dim small" id="lastUpdate"></span>
      </div>
      <div class="card-body">
        <div class="row text-center mb-3">
          <div class="col-3"><div class="text-dim small">Speso</div><div class="fw-bold" id="statSpent">-</div></div>
          <div class="col-3"><div class="text-dim small">Posti liberi</div><div class="fw-bold" id="statSlots">-</div></div>
          <div class="col-3"><div class="text-dim small">Prezzo medio</div><div class="fw-bold" id="statAvg">-</div></div>
          <div class="col-3"><div class="text-dim small">Max spendibile</div><div class="fw-bold" id="statMax">-</div></div>
        </div>
        <div id="roleCounters" class="d-flex gap-2 flex-wrap mb-3"></div>
        <div id="rosterList" class="list-group list-group-flush"></div>
      </div>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php if ($isLive): ?>
<!-- Modale conferma acquisto (autodichiarazione dal partecipante) -->
<div class="modal fade" id="buyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Conferma acquisto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3" id="buyPlayerInfo"></div>
        <div class="mb-2">
          <label class="form-label small">Prezzo pagato</label>
          <input type="number" id="buyPriceInput" class="form-control form-control-lg" placeholder="Prezzo" min="1">
          <div class="form-text">Premi INVIO per confermare.</div>
        </div>
        <div id="buyError" class="alert alert-danger py-1 d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-success" id="btnConfirmBuy">HO PRESO QUESTO GIOCATORE</button>
      </div>
    </div>
  </div>
</div>
<script>
  window.FA_AUCTION_ID = <?= (int)$auction['id'] ?>;
  window.FA_TEAM_ID = <?= $teamId ?>;
</script>
<script src="<?= assetUrl('/assets/js/dashboard.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
