<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$auctionId = (int)($_GET['auction'] ?? 0);
$auction = AuctionService::findById($auctionId);
if ($auction === null) {
    http_response_code(404);
    die('Asta non trovata.');
}

$pageTitle = e($auction['name']) . ' - Display';
$showNav = false;
$bodyClass = 'display-body';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">⚽ <?= e($auction['name']) ?></h2>
  <span class="badge status-badge-<?= e($auction['status']) ?> fs-5" id="displayStatus"><?= e($auction['status']) ?></span>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="display-current text-center" id="currentPlayerBlock">
      <div class="text-dim mb-1">GIOCATORE ATTUALMENTE ALL'ASTA</div>
      <div class="d-flex align-items-center justify-content-center gap-3">
        <div id="dCurrentAvatar"></div>
        <div>
          <div class="player-name" id="dCurrentName">In attesa...</div>
          <div class="player-meta" id="dCurrentMeta">&nbsp;</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="display-last h-100 d-flex align-items-center gap-3">
      <div id="dLastAvatar"></div>
      <div>
        <div class="text-dim mb-1">ULTIMO ACQUISTO</div>
        <div class="fs-4 fw-bold" id="dLastPlayer">-</div>
        <div class="text-dim" id="dLastTeam">-</div>
        <div class="credit-medium credit-positive" id="dLastPrice">-</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3" id="teamsGrid"></div>

<script>window.FA_AUCTION_ID = <?= $auctionId ?>;</script>
<script src="<?= url('/assets/js/display.js') ?>"></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
