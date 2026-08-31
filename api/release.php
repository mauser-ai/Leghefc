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

$result = AuctionService::releasePlayer($auctionId, $purchaseId);
jsonOut($result, $result['success'] ? 200 : 422);
