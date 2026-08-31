<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireLogin();

$auctionId = (int)($_GET['auction'] ?? 0);
if ($auctionId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametro auction mancante'], 400);
}

$state = AuctionService::getAuctionState($auctionId);
if ($state === null) {
    jsonOut(['success' => false, 'error' => 'Asta non trovata'], 404);
}

jsonOut(['success' => true] + $state);
