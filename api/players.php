<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireLogin(); // ricerca usata sia dall'admin sia dai partecipanti per l'auto-assegnazione

$auctionId = (int)($_GET['auction'] ?? 0);
if ($auctionId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametro auction mancante'], 400);
}

$query = (string)($_GET['q'] ?? '');
$role = (string)($_GET['role'] ?? '');
$team = (string)($_GET['team'] ?? '');
$onlyAvailable = ($_GET['available'] ?? '') === '1';
$sortBy = (string)($_GET['sort'] ?? PlayerService::SORT_NAME);

$results = PlayerService::search($auctionId, $query, $role, $team, $onlyAvailable, $sortBy);

// Limita i risultati per non appesantire la UI durante la digitazione.
$results = array_slice($results, 0, 200);

jsonOut(['success' => true, 'players' => $results, 'count' => count($results)]);
