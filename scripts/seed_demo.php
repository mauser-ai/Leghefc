<?php
/**
 * Popola l'applicazione con dati demo per test immediati:
 * 1 admin, 10 utenti, 10 fantateam, 1 asta, 50 giocatori fittizi.
 *
 * Uso: php scripts/seed_demo.php [--force]
 * --force sovrascrive eventuali dati CSV già presenti in /data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$force = in_array('--force', $argv ?? [], true);

$existingUsers = CsvStorage::readAll(Schema::USERS, Schema::USERS_HEADERS);
if (!empty($existingUsers) && !$force) {
    fwrite(STDERR, "I dati sembrano già presenti (users.csv non vuoto). Usa --force per sovrascrivere.\n");
    exit(1);
}

if ($force) {
    foreach ([
        Schema::USERS, Schema::TEAMS, Schema::AUCTIONS, Schema::AUCTION_TEAMS,
        Schema::PLAYERS, Schema::AUCTION_PLAYERS, Schema::PURCHASES,
        Schema::SETTINGS, Schema::CURRENT_AUCTION, Schema::AUDIT,
    ] as $file) {
        $path = CsvStorage::path($file);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "Creazione utente admin...\n";
$admin = UserService::register('admin', 'admin123', 'admin123', 'admin');
if ($admin['ok']) {
    UserService::setActive((int)$admin['user']['id'], true); // già attivo di default
} else {
    echo "  -> " . $admin['error'] . "\n";
}

echo "Creazione asta demo...\n";
$auction = AuctionService::createAuction([
    'name' => 'Asta Demo Fantacalcio 2026',
    'invite_code' => 'DEMO26',
    'auction_date' => date('Y-m-d', strtotime('+30 days')),
    'initial_budget' => 500,
    'goalkeepers' => 3,
    'defenders' => 8,
    'midfielders' => 8,
    'attackers' => 6,
]);
$auctionId = (int)$auction['id'];
echo "  -> Asta #$auctionId creata (codice invito: DEMO26)\n";

echo "Creazione 50 giocatori fittizi...\n";
$firstNames = ['Marco', 'Luca', 'Andrea', 'Matteo', 'Davide', 'Simone', 'Alessio', 'Fabio', 'Stefano', 'Nicola', 'Paolo', 'Roberto', 'Giulio', 'Enrico', 'Riccardo'];
$lastNames = ['Bianchi', 'Ferrari', 'Russo', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo', 'Conti', 'Villa', 'Mancini', 'Costa', 'Fontana', 'Rinaldi'];
$fakeTeams = ['Grifoni FC', 'Vulcano United', 'Falchi Calcio', 'Aquile Rossoblu', 'Lupi Sport Club', 'Tigre Verde FC', 'Draghi Azzurri', 'Pantere FC', 'Leoni United', 'Orsi Calcio'];

function randomName(array $first, array $last): string
{
    return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
}

$players = [];
$roleCounts = [Schema::ROLE_GK => 8, Schema::ROLE_DEF => 16, Schema::ROLE_MID => 16, Schema::ROLE_ATT => 10];
foreach ($roleCounts as $role => $count) {
    for ($i = 0; $i < $count; $i++) {
        $quotation = match ($role) {
            Schema::ROLE_GK => rand(5, 25),
            Schema::ROLE_DEF => rand(3, 20),
            Schema::ROLE_MID => rand(5, 35),
            Schema::ROLE_ATT => rand(8, 45),
            default => 10,
        };
        $players[] = [
            'name' => randomName($firstNames, $lastNames),
            'real_team' => $fakeTeams[array_rand($fakeTeams)],
            'role' => $role,
            'quotation' => (string)$quotation,
            'fvm' => (string)($quotation * rand(6, 10)),
        ];
    }
}
ImportService::importPlayers($players);
echo "  -> " . count($players) . " giocatori importati nel listone.\n";

echo "Creazione 10 utenti e fantateam demo...\n";
for ($i = 1; $i <= 10; $i++) {
    $nickname = "user$i";
    $result = UserService::register($nickname, 'demo123', 'demo123');
    if (!$result['ok']) {
        echo "  -> Utente $nickname: " . $result['error'] . "\n";
        continue;
    }
    $userId = (int)$result['user']['id'];
    $team = TeamService::createTeam($userId, "Fantateam $i", "Mister $i");
    AuctionService::joinAuction($auctionId, (int)$team['id']);
    echo "  -> $nickname / Fantateam $i creato e associato all'asta demo.\n";
}

// Assicura che anche i file non ancora scritti esistano con la sola intestazione.
CsvStorage::ensure(Schema::PURCHASES, Schema::PURCHASES_HEADERS);
CsvStorage::ensure(Schema::SETTINGS, Schema::SETTINGS_HEADERS);
CsvStorage::ensure(Schema::CURRENT_AUCTION, Schema::CURRENT_AUCTION_HEADERS);

echo "\nFatto! Credenziali demo:\n";
echo "  Admin:   admin / admin123\n";
echo "  Utenti:  user1..user10 / demo123\n";
echo "  Codice invito asta: DEMO26\n";
