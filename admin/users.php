<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
requireAdmin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = UserService::findById($userId);
        if ($user !== null) {
            UserService::setActive($userId, (int)$user['active'] !== 1);
            $message = 'Stato utente aggiornato.';
        }
    } elseif ($action === 'associate') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if ($teamId > 0 && $auctionId > 0) {
            AuctionService::joinAuction($auctionId, $teamId);
            $message = 'Squadra associata all\'asta.';
        }
    } elseif ($action === 'dissociate') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if ($teamId > 0 && $auctionId > 0) {
            AuctionService::leaveAuction($auctionId, $teamId);
            $message = 'Squadra rimossa dall\'asta (utente NON eliminato).';
        }
    }
}

$users = UserService::listAll();
$teamsByUser = [];
foreach (TeamService::listAllTeams() as $t) {
    if ((int)$t['active'] === 1) {
        $teamsByUser[(int)$t['user_id']] = $t;
    }
}
$auctions = AuctionService::listAll();

$pageTitle = 'Utenti - Admin';
$showNav = true;
require __DIR__ . '/../partials/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">👥 Gestione Utenti</h2>
    <a href="/admin/index.php" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Nickname</th>
          <th>Squadra</th>
          <th>Registrato</th>
          <th>Ultimo accesso</th>
          <th>Stato</th>
          <th>Ruolo</th>
          <th>Aste associate</th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $uid = (int)$u['id'];
            $team = $teamsByUser[$uid] ?? null;
            $links = $team !== null ? TeamService::getTeamAuctionLinks((int)$team['id']) : [];
        ?>
        <tr>
          <td><?= e($u['nickname']) ?></td>
          <td><?= $team !== null ? e($team['name']) : '<span class="text-dim">-- non configurata --</span>' ?></td>
          <td class="small text-dim"><?= e($u['created_at']) ?></td>
          <td class="small text-dim"><?= e($u['last_login'] ?: '-') ?></td>
          <td>
            <span class="badge <?= (int)$u['active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
              <?= (int)$u['active'] === 1 ? 'Attivo' : 'Disattivo' ?>
            </span>
          </td>
          <td><span class="badge bg-dark"><?= e($u['role']) ?></span></td>
          <td>
            <?php if ($team === null): ?>
              <span class="text-dim">-</span>
            <?php else: ?>
              <?php foreach ($links as $link):
                  $a = AuctionService::findById((int)$link['auction_id']);
                  if ($a === null) continue;
              ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="badge status-badge-<?= e($a['status']) ?>"><?= e($a['name']) ?></span>
                  <form method="post" onsubmit="return confirm('Rimuovere la squadra da questa asta?');">
                    <input type="hidden" name="action" value="dissociate">
                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                    <input type="hidden" name="auction_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">&times;</button>
                  </form>
                </div>
              <?php endforeach; ?>
              <?php if (!empty($auctions)): ?>
              <form method="post" class="d-flex gap-1 mt-1">
                <input type="hidden" name="action" value="associate">
                <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                <select name="auction_id" class="form-select form-select-sm" style="width:auto">
                  <option value="">+ associa asta</option>
                  <?php foreach ($auctions as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary">Ok</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Confermi il cambio di stato?');">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="user_id" value="<?= $uid ?>">
              <button type="submit" class="btn btn-sm btn-outline-warning">
                <?= (int)$u['active'] === 1 ? 'Disattiva' : 'Attiva' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
