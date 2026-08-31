<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireAdmin();

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

$result = AuctionService::buyPlayer($auctionId, $playerId, $teamId, $price);
jsonOut($result, $result['success'] ? 200 : 422);
