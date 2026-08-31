<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
requireAdmin();

$auctions = AuctionService::listAll();
$users = UserService::listAll();
$players = PlayerService::listAll();

$pageTitle = 'Area Admin - Fantacalcio Asta';
$showNav = true;
require __DIR__ . '/../partials/header.php';
?>
<div class="container py-4">
  <h2 class="mb-4">🛠️ Area Amministrazione</h2>

  <div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
      <div class="card text-center">
        <div class="card-body">
          <div class="credit-medium"><?= count($users) ?></div>
          <div class="text-dim small">Utenti registrati</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card text-center">
        <div class="card-body">
          <div class="credit-medium"><?= count($auctions) ?></div>
          <div class="text-dim small">Aste create</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card text-center">
        <div class="card-body">
          <div class="credit-medium"><?= count($players) ?></div>
          <div class="text-dim small">Giocatori a listone</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card text-center">
        <div class="card-body">
          <div class="credit-medium"><?= count(array_filter($auctions, fn($a) => $a['status'] === Schema::STATUS_LIVE)) ?></div>
          <div class="text-dim small">Aste LIVE</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5>👥 Utenti</h5>
          <p class="text-dim">Gestisci account, attivazione e associazioni squadra/asta.</p>
          <a href="/admin/users.php" class="btn btn-primary btn-sm">Gestisci utenti</a>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5>🏆 Aste</h5>
          <p class="text-dim">Crea nuove aste, configura budget e limiti rosa, gestisci lo stato.</p>
          <a href="/admin/auctions.php" class="btn btn-primary btn-sm">Gestisci aste</a>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5>📥 Listone</h5>
          <p class="text-dim">Importa il listone giocatori da CSV/XLSX con mappatura colonne.</p>
          <a href="/import.php" class="btn btn-outline-primary btn-sm">Importa giocatori</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
