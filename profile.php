<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$userId = Auth::userId();
$team = TeamService::getTeamByUser($userId);
$isFirst = isset($_GET['first']) && $team === null;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['team_name'] ?? ''));
    $coach = trim((string)($_POST['coach_name'] ?? ''));
    $logo = trim((string)($_POST['logo'] ?? ''));

    if ($name === '') {
        $error = 'Il nome della squadra è obbligatorio.';
    } else {
        if ($team === null) {
            $team = TeamService::createTeam($userId, $name, $coach, $logo);
        } else {
            TeamService::updateTeam((int)$team['id'], ['name' => $name, 'coach_name' => $coach, 'logo' => $logo]);
            $team = TeamService::getTeamById((int)$team['id']);
        }
        $success = 'Squadra salvata correttamente.';
        if ($isFirst) {
            redirect('/dashboard.php');
        }
    }
}

$auctionLinks = $team !== null ? TeamService::getTeamAuctionLinks((int)$team['id']) : [];

$pageTitle = 'Il mio Team - Fantacalcio Asta';
$showNav = true;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4" style="max-width:720px;">
  <?php if ($isFirst): ?>
    <div class="alert alert-info">
      <strong>Benvenuto, <?= e(Auth::nickname()) ?>!</strong> Prima di continuare configura il nome del tuo fantateam.
      Potrai modificarlo in qualsiasi momento, anche settimane prima dell'asta.
    </div>
  <?php endif; ?>

  <h2 class="mb-4">👕 Il mio Fantateam</h2>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success && !$isFirst): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Nome fantateam <span class="text-danger">*</span></label>
          <input type="text" name="team_name" class="form-control" required
                 value="<?= e($team['name'] ?? '') ?>" placeholder="Es. Riccardo FC">
        </div>
        <div class="mb-3">
          <label class="form-label">Nome allenatore <span class="text-dim">(opzionale)</span></label>
          <input type="text" name="coach_name" class="form-control" value="<?= e($team['coach_name'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">URL Logo <span class="text-dim">(opzionale)</span></label>
          <input type="text" name="logo" class="form-control" value="<?= e($team['logo'] ?? '') ?>" placeholder="https://...">
        </div>
        <button type="submit" class="btn btn-success">Salva squadra</button>
      </form>
    </div>
  </div>

  <?php if ($team !== null): ?>
  <div class="card">
    <div class="card-header">Le tue aste</div>
    <div class="card-body">
      <?php if (empty($auctionLinks)): ?>
        <p class="text-dim mb-2">Non sei ancora associato a nessuna asta.</p>
        <a href="/join-auction.php" class="btn btn-primary btn-sm">Inserisci codice invito</a>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($auctionLinks as $link):
              $auction = AuctionService::findById((int)$link['auction_id']);
              if ($auction === null) continue;
          ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <div>
                <strong><?= e($auction['name']) ?></strong>
                <div class="text-dim small"><?= e($auction['auction_date']) ?> · Budget <?= e($auction['initial_budget']) ?></div>
              </div>
              <span class="badge status-badge-<?= e($auction['status']) ?>"><?= e($auction['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <a href="/join-auction.php" class="btn btn-outline-primary btn-sm mt-3">Entra in un'altra asta</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
