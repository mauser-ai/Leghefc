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

$remainingBudget = $auction !== null ? AuctionService::getRemainingBudget($auction, $teamId) : null;
$isLive = $auction !== null && $auction['status'] === Schema::STATUS_LIVE;
$realTeams = $isLive ? PlayerService::realTeams() : [];

$pageTitle = 'Dashboard - Fantacalcio Asta';
$showNav = false;
$bodyClass = 'app-shell';
require __DIR__ . '/partials/header.php';
?>
<div class="app-topbar">
  <div>
    <div class="fw-bold"><?= e($team['name']) ?></div>
    <div class="text-dim small"><?= $auction !== null ? e($auction['name']) : 'Nessuna asta' ?></div>
  </div>
  <button class="app-topbar-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuOffcanvas" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="menuOffcanvas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">👤 <?= e(Auth::nickname()) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Chiudi"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column gap-2">
    <?php if (count($auctions) > 1): ?>
      <form method="get" class="mb-2">
        <label class="form-label small text-dim">Asta attiva</label>
        <select name="auction" class="form-select" onchange="this.form.submit()">
          <?php foreach ($auctions as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ($auction !== null && (int)$a['id'] === (int)$auction['id']) ? 'selected' : '' ?>>
              <?= e($a['name']) ?> (<?= e($a['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <a href="<?= url('/profile.php') ?>" class="btn btn-outline-light text-start"><i class="bi bi-person me-2"></i>Il mio team</a>
    <a href="<?= url('/join-auction.php') ?>" class="btn btn-outline-light text-start"><i class="bi bi-key me-2"></i>Entra in un'altra asta</a>
    <?php if (Auth::isAdmin()): ?>
      <a href="<?= url('/admin/index.php') ?>" class="btn btn-outline-light text-start"><i class="bi bi-gear me-2"></i>Admin</a>
    <?php endif; ?>
    <a href="<?= url('/logout.php') ?>" class="btn btn-outline-danger text-start mt-auto"><i class="bi bi-box-arrow-right me-2"></i>Esci</a>
  </div>
</div>

<?php if ($auction === null): ?>
  <div class="p-3">
    <div class="alert alert-info mt-3">
      Non sei ancora associato a nessuna asta. <a href="<?= url('/join-auction.php') ?>">Inserisci un codice invito</a> per iniziare.
    </div>
  </div>
<?php else: ?>

  <div class="tab-content app-content-mobile">

    <!-- TAB: La mia situazione -->
    <div class="tab-pane-mobile active" id="tabMe">
      <div class="p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span class="badge status-badge-<?= e($auction['status']) ?>"><?= e($auction['status']) ?></span>
          <span class="text-dim small">Data asta: <?= e($auction['auction_date'] ?: '-') ?></span>
        </div>

        <?php if (!$isLive): ?>
          <div class="alert alert-warning text-center fw-bold py-2 mb-3">⏳ L'asta non è ancora iniziata</div>
        <?php endif; ?>

        <div class="row text-center g-2 mb-3">
          <div class="col-3">
            <div class="text-dim small">Iniziali</div>
            <div class="fw-bold"><?= (int)$auction['initial_budget'] ?></div>
          </div>
          <div class="col-3">
            <div class="text-dim small">Residui</div>
            <div class="credit-medium credit-positive" id="remainingBudget"><?= (int)$remainingBudget ?></div>
          </div>
          <div class="col-3">
            <div class="text-dim small">Speso</div>
            <div class="fw-bold" id="statSpent">-</div>
          </div>
          <div class="col-3">
            <div class="text-dim small">Max</div>
            <div class="fw-bold" id="statMax">-</div>
          </div>
        </div>

        <div id="roleCounters" class="d-flex gap-2 flex-wrap mb-3"></div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">📋 La mia rosa</h6>
          <span class="text-dim small" id="lastUpdate"></span>
        </div>
        <div id="rosterList" class="list-group list-group-flush"></div>
      </div>
    </div>

    <!-- TAB: Asta in corso -->
    <div class="tab-pane-mobile" id="tabAuction">
      <div class="p-3">
        <?php if (!$isLive): ?>
          <div class="alert alert-warning text-center mt-3">L'asta non è ancora in corso. Torna qui quando l'admin la avvia.</div>
        <?php else: ?>
          <p class="text-dim small mb-2">Tocca un giocatore per dichiarare l'acquisto.</p>
          <input type="text" id="buySearchQuery" class="form-control mb-2" placeholder="Cerca per nome...">
          <div class="row g-2 mb-3">
            <div class="col-4">
              <select id="buyFilterRole" class="form-select">
                <option value="">Ruolo</option>
                <option value="P">Portieri</option>
                <option value="D">Difensori</option>
                <option value="C">Centrocampisti</option>
                <option value="A">Attaccanti</option>
              </select>
            </div>
            <div class="col-4">
              <select id="buyFilterTeam" class="form-select">
                <option value="">Squadra</option>
                <?php foreach ($realTeams as $rt): ?>
                  <option value="<?= e($rt) ?>"><?= e($rt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <select id="buyFilterSort" class="form-select">
                <option value="name">Nome</option>
                <option value="role">Ruolo</option>
                <option value="quotation">Quotazione</option>
                <option value="fvm">FVM</option>
              </select>
            </div>
          </div>
          <div id="buyPlayerResults"></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: Situazione lega -->
    <div class="tab-pane-mobile" id="tabLeague">
      <div class="p-3">
        <p class="text-dim small mb-2">Tocca una squadra per vedere la rosa.</p>
        <div id="leagueTeams" class="d-flex flex-column gap-2"></div>
      </div>
    </div>

  </div>

  <nav class="app-tabbar">
    <button type="button" class="app-tabbar-item active" data-tab="tabMe">
      <i class="bi bi-person-fill"></i><span>La mia situazione</span>
    </button>
    <button type="button" class="app-tabbar-item" data-tab="tabAuction">
      <i class="bi bi-hammer"></i><span>Asta in corso</span>
    </button>
    <button type="button" class="app-tabbar-item" data-tab="tabLeague">
      <i class="bi bi-bar-chart-fill"></i><span>Situazione lega</span>
    </button>
  </nav>

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
  <?php endif; ?>

  <script>
    window.FA_AUCTION_ID = <?= (int)$auction['id'] ?>;
    window.FA_TEAM_ID = <?= $teamId ?>;
    window.FA_AUCTION_LIVE = <?= $isLive ? 'true' : 'false' ?>;
  </script>
  <script src="<?= assetUrl('/assets/js/dashboard.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
