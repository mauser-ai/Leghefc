<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'error' => 'Metodo non consentito'], 405);
}

$body = jsonBody();
$auctionId = (int)($body['auction_id'] ?? 0);
$purchaseId = isset($body['purchase_id']) ? (int)$body['purchase_id'] : null;

if ($auctionId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametri mancanti'], 400);
}

$result = $purchaseId
    ? AuctionService::undoPurchase($auctionId, $purchaseId)
    : AuctionService::undoLastPurchase($auctionId);

jsonOut($result, $result['success'] ? 200 : 422);
