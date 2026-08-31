<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $auctionId = (int)($_GET['auction'] ?? 0);
    if ($auctionId <= 0) {
        jsonOut(['success' => false, 'error' => 'Parametro auction mancante'], 400);
    }
    jsonOut(['success' => true, 'player' => AuctionService::getCurrentPlayer($auctionId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::apiRequireAdmin();
    $body = jsonBody();
    $auctionId = (int)($body['auction_id'] ?? 0);
    $playerId = isset($body['player_id']) && $body['player_id'] !== '' ? (int)$body['player_id'] : null;

    if ($auctionId <= 0) {
        jsonOut(['success' => false, 'error' => 'Parametri mancanti'], 400);
    }

    if ($playerId !== null && !PlayerService::isAvailable($auctionId, $playerId)) {
        jsonOut(['success' => false, 'error' => 'Giocatore non disponibile'], 422);
    }

    AuctionService::setCurrentPlayer($auctionId, $playerId);
    $player = PlayerService::findById($playerId ?? 0);
    AuditService::log('SET_CURRENT_PLAYER', $auctionId, $playerId, null, null, null, $player['name'] ?? '');

    jsonOut(['success' => true]);
}

jsonOut(['success' => false, 'error' => 'Metodo non consentito'], 405);
