<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'error' => 'Metodo non consentito'], 405);
}

$body = jsonBody();
$auctionId = (int)($body['auction_id'] ?? 0);
$purchaseId = (int)($body['purchase_id'] ?? 0);

if ($auctionId <= 0 || $purchaseId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametri mancanti'], 400);
}

$newPrice = isset($body['price']) && $body['price'] !== '' ? (int)$body['price'] : null;
$newTeamId = isset($body['team_id']) && $body['team_id'] !== '' ? (int)$body['team_id'] : null;
$newPlayerId = isset($body['player_id']) && $body['player_id'] !== '' ? (int)$body['player_id'] : null;

$result = AuctionService::updatePurchase($auctionId, $purchaseId, $newPrice, $newTeamId, $newPlayerId);
jsonOut($result, $result['success'] ? 200 : 422);
