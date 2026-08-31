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

$type = (string)($_GET['type'] ?? '');
$teamId = isset($_GET['team']) ? (int)$_GET['team'] : null;

// Un utente non admin può esportare solo la propria squadra.
if (!Auth::isAdmin()) {
    $myTeam = TeamService::getTeamByUser(Auth::userId());
    if ($myTeam === null || ($teamId !== null && $teamId !== (int)$myTeam['id'])) {
        http_response_code(403);
        die('Non autorizzato.');
    }
    $teamId = (int)$myTeam['id'];
    if ($type === '' || str_ends_with($type, '_full') || $type === 'zip_all') {
        $type = 'csv_team';
    }
}

function slug(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9\-]+/', '_', $s) ?? 'export';
    return trim($s, '_') ?: 'export';
}

$auctionSlug = slug($auction['name']);

if ($type === '') {
    $pageTitle = 'Export - Fantacalcio Asta';
    $showNav = true;
    require __DIR__ . '/partials/header.php';
    $teams = TeamService::getAuctionTeams($auctionId);
    ?>
    <div class="container py-4" style="max-width:700px;">
      <h2 class="mb-4">⬇️ Export rose - <?= e($auction['name']) ?></h2>
      <div class="card">
        <div class="card-body d-flex flex-column gap-2">
          <?php if (Auth::isAdmin()): ?>
            <a class="btn btn-success" href="?auction=<?= $auctionId ?>&type=csv_full">CSV completo asta</a>
            <a class="btn btn-outline-success" href="?auction=<?= $auctionId ?>&type=xlsx_full">XLSX completo asta</a>
            <a class="btn btn-outline-primary" href="?auction=<?= $auctionId ?>&type=zip_all">ZIP con tutte le rose (CSV per squadra)</a>
            <hr>
            <p class="text-dim mb-1">Export per singola squadra:</p>
            <?php foreach ($teams as $t): ?>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-light flex-fill" href="?auction=<?= $auctionId ?>&type=csv_team&team=<?= (int)$t['id'] ?>">CSV <?= e($t['name']) ?></a>
                <a class="btn btn-sm btn-outline-light" href="?auction=<?= $auctionId ?>&type=xlsx_team&team=<?= (int)$t['id'] ?>">XLSX</a>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <a class="btn btn-success" href="?auction=<?= $auctionId ?>&type=csv_team">Esporta la mia rosa (CSV)</a>
            <a class="btn btn-outline-success" href="?auction=<?= $auctionId ?>&type=xlsx_team">Esporta la mia rosa (XLSX)</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

switch ($type) {
    case 'csv_full':
        $rows = FantacalcioExporter::buildRows($auctionId);
        $content = FantacalcioExporter::toCsvString($rows);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $auctionSlug . '_completo.csv"');
        echo $content;
        exit;

    case 'csv_team':
        if ($teamId === null) {
            http_response_code(400);
            die('Parametro team mancante.');
        }
        $team = TeamService::getTeamById($teamId);
        $rows = FantacalcioExporter::buildRows($auctionId, $teamId);
        $content = FantacalcioExporter::toCsvString($rows);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $auctionSlug . '_' . slug($team['name'] ?? 'squadra') . '.csv"');
        echo $content;
        exit;

    case 'xlsx_full':
    case 'xlsx_team':
        $rows = FantacalcioExporter::buildRows($auctionId, $type === 'xlsx_team' ? $teamId : null);
        $file = FantacalcioExporter::toXlsxFile($rows, $auction['name']);
        if ($file === null) {
            http_response_code(501);
            die('Export XLSX non disponibile: installa PhpSpreadsheet via composer, oppure usa il formato CSV.');
        }
        $name = $type === 'xlsx_team'
            ? $auctionSlug . '_' . slug(TeamService::getTeamById($teamId)['name'] ?? 'squadra') . '.xlsx'
            : $auctionSlug . '_completo.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        readfile($file);
        @unlink($file);
        exit;

    case 'zip_all':
        if (!Auth::isAdmin()) {
            http_response_code(403);
            die('Non autorizzato.');
        }
        if (!class_exists('ZipArchive')) {
            http_response_code(501);
            die('Estensione PHP zip non disponibile su questo hosting.');
        }
        $tmpZip = tempnam(sys_get_temp_dir(), 'roster_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (TeamService::getAuctionTeams($auctionId) as $t) {
            $rows = FantacalcioExporter::buildRows($auctionId, (int)$t['id']);
            $csv = FantacalcioExporter::toCsvString($rows);
            $zip->addFromString(slug($t['name']) . '.csv', $csv);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $auctionSlug . '_rose.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;

    default:
        http_response_code(400);
        die('Tipo di export non valido.');
}
