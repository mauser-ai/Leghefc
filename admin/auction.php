<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
requireAdmin();

$auctionId = (int)($_GET['id'] ?? 0);
$auction = AuctionService::findById($auctionId);
if ($auction === null) {
    http_response_code(404);
    die('Asta non trovata.');
}

$realTeams = PlayerService::realTeams();

$pageTitle = e($auction['name']) . ' - Gestione LIVE';
$showNav = true;
require __DIR__ . '/../partials/header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h4 class="mb-0"><?= e($auction['name']) ?>
        <span class="badge status-badge-<?= e($auction['status']) ?>" id="auctionStatusBadge"><?= e($auction['status']) ?></span>
      </h4>
      <span class="text-dim small">Codice invito: <strong><?= e($auction['invite_code']) ?></strong> · Budget <?= (int)$auction['initial_budget'] ?> ·
        Rosa <?= (int)$auction['goalkeepers'] ?>/<?= (int)$auction['defenders'] ?>/<?= (int)$auction['midfielders'] ?>/<?= (int)$auction['attackers'] ?></span>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= url('/display.php') ?>?auction=<?= $auctionId ?>" target="_blank" class="btn btn-outline-info btn-sm">📺 Apri Display</a>
      <button class="btn btn-outline-danger btn-sm" id="btnUndoLast">↩️ Annulla ultima operazione</button>
      <a href="<?= url('/admin/auctions.php') ?>" class="btn btn-outline-secondary btn-sm">&larr; Aste</a>
    </div>
  </div>

  <?php if ($auction['status'] !== Schema::STATUS_LIVE): ?>
    <div class="alert alert-warning">Questa asta non è in stato LIVE (stato attuale: <strong><?= e($auction['status']) ?></strong>). Puoi comunque consultare rose e storico, ma gli acquisti sono disabilitati finché non la porti in LIVE da "Gestione Aste".</div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- SINISTRA: ricerca -->
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">🔍 Ricerca giocatore <span class="text-dim fw-normal small">— clicca un giocatore per assegnarlo</span></div>
        <div class="card-body">
          <input type="text" id="searchQuery" class="form-control mb-2" placeholder="Cerca per nome...">
          <div class="row g-2 mb-2">
            <div class="col-4">
              <select id="filterRole" class="form-select form-select-sm">
                <option value="">Tutti i ruoli</option>
                <option value="P">Portieri</option>
                <option value="D">Difensori</option>
                <option value="C">Centrocampisti</option>
                <option value="A">Attaccanti</option>
              </select>
            </div>
            <div class="col-4">
              <select id="filterTeam" class="form-select form-select-sm">
                <option value="">Tutte le squadre</option>
                <?php foreach ($realTeams as $rt): ?>
                  <option value="<?= e($rt) ?>"><?= e($rt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <select id="filterSort" class="form-select form-select-sm">
                <option value="name">Ordina: Nome</option>
                <option value="role">Ordina: Ruolo</option>
                <option value="quotation">Ordina: Quotazione</option>
                <option value="fvm">Ordina: Fantamedia (FVM)</option>
              </select>
            </div>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="filterAvailable" checked>
            <label class="form-check-label small" for="filterAvailable">Solo disponibili</label>
          </div>
          <div id="playerResults" class="admin-live-col"></div>
        </div>
      </div>
    </div>

    <!-- DESTRA: fantateam -->
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">🏟️ Fantateam</div>
        <div class="card-body admin-live-col" id="teamsList">
          <p class="text-dim">Caricamento...</p>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header">🧾 Storico acquisti recenti</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-hover">
          <thead><tr><th>Ora</th><th>Giocatore</th><th>Ruolo</th><th>Squadra</th><th>Prezzo</th><th>Azioni</th></tr></thead>
          <tbody id="purchasesHistory"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modale assegnazione giocatore (aperta al click su un risultato di ricerca) -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assegna giocatore</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3" id="assignPlayerInfo"></div>
        <div class="mb-2">
          <label class="form-label small">Squadra assegnataria</label>
          <select id="assignTeamId" class="form-select"></select>
        </div>
        <div class="mb-2">
          <label class="form-label small">Offerta massima consentita per la squadra selezionata</label>
          <div id="assignMaxBidLabel" class="fw-bold credit-positive">-</div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Prezzo</label>
          <input type="number" id="assignPriceInput" class="form-control form-control-lg" placeholder="Prezzo" min="1">
          <div class="form-text">Premi INVIO per confermare.</div>
        </div>
        <div id="assignError" class="alert alert-danger py-1 d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-warning me-auto" id="btnCallPlayerFromModal">📣 Metti all'asta</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-success" id="btnAssign">ASSEGNA</button>
      </div>
    </div>
  </div>
</div>

<!-- Modale modifica acquisto -->
<div class="modal fade" id="editPurchaseModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Modifica acquisto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editPurchaseId">
        <div class="mb-2">
          <label class="form-label small">Prezzo</label>
          <input type="number" id="editPrice" class="form-control" min="1">
        </div>
        <div class="mb-2">
          <label class="form-label small">Squadra assegnataria</label>
          <select id="editTeamId" class="form-select"></select>
        </div>
        <div id="editError" class="alert alert-danger py-1 d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-primary" id="btnSaveEdit">Salva</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.FA_AUCTION_ID = <?= $auctionId ?>;
  window.FA_AUCTION_LIVE = <?= $auction['status'] === Schema::STATUS_LIVE ? 'true' : 'false' ?>;
</script>
<script src="<?= url('/assets/js/admin-auction.js') ?>"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
