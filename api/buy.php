<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'error' => 'Metodo non consentito'], 405);
}

$body = jsonBody();
$auctionId = (int)($body['auction_id'] ?? 0);
$playerId = (int)($body['player_id'] ?? 0);
$teamId = (int)($body['team_id'] ?? 0);
$price = (int)($body['price'] ?? 0);

if ($auctionId <= 0 || $playerId <= 0 || $teamId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametri mancanti'], 400);
}

// Un partecipante (non admin) può acquistare solo per la propria squadra:
// il team_id inviato dal client deve corrispondere a quello posseduto dall'utente.
if (!Auth::isAdmin()) {
    $ownTeam = TeamService::getTeamByUser(Auth::userId());
    if ($ownTeam === null || (int)$ownTeam['id'] !== $teamId) {
        jsonOut(['success' => false, 'error' => 'Puoi acquistare solo per la tua squadra.'], 403);
    }
}

$result = AuctionService::buyPlayer($auctionId, $playerId, $teamId, $price);
jsonOut($result, $result['success'] ? 200 : 422);
