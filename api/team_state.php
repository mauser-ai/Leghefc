<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::apiRequireLogin();

$auctionId = (int)($_GET['auction'] ?? 0);
$teamId = (int)($_GET['team'] ?? 0);

if ($auctionId <= 0 || $teamId <= 0) {
    jsonOut(['success' => false, 'error' => 'Parametri mancanti'], 400);
}

$auction = AuctionService::findById($auctionId);
$team = TeamService::getTeamById($teamId);
if ($auction === null || $team === null) {
    jsonOut(['success' => false, 'error' => 'Asta o squadra non trovata'], 404);
}

// Verifica ownership: solo il proprietario della squadra o un admin può vedere questi dati.
if (!Auth::isAdmin() && (int)$team['user_id'] !== Auth::userId()) {
    jsonOut(['success' => false, 'error' => 'Non autorizzato'], 403);
}

$roster = AuctionService::getTeamRoster($auctionId, $teamId);
$roleCounts = AuctionService::getRoleCounts($auctionId, $teamId);
$remaining = AuctionService::getRemainingBudget($auction, $teamId);
$spent = (int)$auction['initial_budget'] - $remaining;

$roleLimits = [
    Schema::ROLE_GK => (int)$auction['goalkeepers'],
    Schema::ROLE_DEF => (int)$auction['defenders'],
    Schema::ROLE_MID => (int)$auction['midfielders'],
    Schema::ROLE_ATT => (int)$auction['attackers'],
];

$avgByRole = [];
foreach (Schema::ROLES as $role) {
    $prices = array_map(fn($r) => $r['price'], array_filter($roster, fn($r) => $r['role'] === $role));
    $avgByRole[$role] = count($prices) > 0 ? round(array_sum($prices) / count($prices), 1) : 0;
}

$lastPurchase = null;
foreach (array_reverse($roster) as $r) {
    $lastPurchase = $r;
    break;
}

jsonOut([
    'success' => true,
    'team' => ['id' => $teamId, 'name' => $team['name'], 'coach_name' => $team['coach_name']],
    'initial_budget' => (int)$auction['initial_budget'],
    'spent' => $spent,
    'remaining_budget' => $remaining,
    'players_count' => count($roster),
    'slots_total' => AuctionService::getSquadSize($auction),
    'slots_free' => AuctionService::getSquadSize($auction) - count($roster),
    'role_counts' => $roleCounts,
    'role_limits' => $roleLimits,
    'avg_price_by_role' => $avgByRole,
    'max_bid' => AuctionService::getMaximumBid($auction, $auctionId, $teamId),
    'roster' => $roster,
    'last_purchase' => $lastPurchase,
    'current_player' => AuctionService::getCurrentPlayer($auctionId),
    'auction_status' => $auction['status'],
    'timestamp' => now(),
]);
