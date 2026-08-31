<?php
/**
 * Gestione del listone giocatori (globale) e della disponibilità
 * per singola asta (auction_players.csv).
 */

declare(strict_types=1);

final class PlayerService
{
    public static function listAll(): array
    {
        return CsvStorage::readAll(Schema::PLAYERS, Schema::PLAYERS_HEADERS);
    }

    public static function findById(int $id): ?array
    {
        return CsvStorage::findOne(Schema::PLAYERS, Schema::PLAYERS_HEADERS, fn($p) => (int)$p['id'] === $id);
    }

    /**
     * Sostituisce interamente il listone (usato dall'import).
     */
    public static function replaceAll(array $players): void
    {
        $rows = [];
        $id = 1;
        foreach ($players as $p) {
            $rows[] = [
                'id' => (string)$id++,
                'name' => $p['name'],
                'real_team' => $p['real_team'],
                'role' => $p['role'],
                'quotation' => $p['quotation'],
                'fvm' => $p['fvm'],
            ];
        }
        CsvStorage::writeAll(Schema::PLAYERS, $rows, Schema::PLAYERS_HEADERS);
    }

    /**
     * Assicura che tutti i giocatori del listone globale abbiano una riga
     * di disponibilità per la specifica asta (default: disponibile).
     */
    public static function ensureAuctionPlayers(int $auctionId): void
    {
        CsvStorage::transaction(Schema::AUCTION_PLAYERS, Schema::AUCTION_PLAYERS_HEADERS, function (array $rows) use ($auctionId) {
            $existing = [];
            foreach ($rows as $r) {
                if ((int)$r['auction_id'] === $auctionId) {
                    $existing[(int)$r['player_id']] = true;
                }
            }
            $players = self::listAll();
            foreach ($players as $p) {
                $pid = (int)$p['id'];
                if (!isset($existing[$pid])) {
                    $rows[] = [
                        'auction_id' => (string)$auctionId,
                        'player_id' => (string)$pid,
                        'available' => '1',
                    ];
                }
            }
            return $rows;
        });
    }

    /**
     * Mappa player_id => available (bool) per una specifica asta.
     */
    public static function availabilityMap(int $auctionId): array
    {
        $map = [];
        foreach (CsvStorage::readAll(Schema::AUCTION_PLAYERS, Schema::AUCTION_PLAYERS_HEADERS) as $r) {
            if ((int)$r['auction_id'] === $auctionId) {
                $map[(int)$r['player_id']] = (int)$r['available'] === 1;
            }
        }
        return $map;
    }

    public static function isAvailable(int $auctionId, int $playerId): bool
    {
        $row = CsvStorage::findOne(Schema::AUCTION_PLAYERS, Schema::AUCTION_PLAYERS_HEADERS,
            fn($r) => (int)$r['auction_id'] === $auctionId && (int)$r['player_id'] === $playerId
        );
        return $row === null ? true : ((int)$row['available'] === 1);
    }

    public static function setAvailability(int $auctionId, int $playerId, bool $available): void
    {
        $updated = CsvStorage::update(Schema::AUCTION_PLAYERS, Schema::AUCTION_PLAYERS_HEADERS,
            fn($r) => (int)$r['auction_id'] === $auctionId && (int)$r['player_id'] === $playerId,
            function ($r) use ($available) {
                $r['available'] = $available ? '1' : '0';
                return $r;
            }
        );
        if ($updated === 0) {
            CsvStorage::append(Schema::AUCTION_PLAYERS, Schema::AUCTION_PLAYERS_HEADERS, [
                'auction_id' => (string)$auctionId,
                'player_id' => (string)$playerId,
                'available' => $available ? '1' : '0',
            ]);
        }
    }

    /**
     * Ricerca giocatori per una specifica asta con filtri.
     */
    public static function search(int $auctionId, string $query = '', string $role = '', string $realTeam = '', bool $onlyAvailable = false): array
    {
        $availability = self::availabilityMap($auctionId);
        $query = mb_strtolower(trim($query));

        $results = [];
        foreach (self::listAll() as $p) {
            $pid = (int)$p['id'];
            $available = $availability[$pid] ?? true;

            if ($role !== '' && $p['role'] !== $role) {
                continue;
            }
            if ($realTeam !== '' && strcasecmp($p['real_team'], $realTeam) !== 0) {
                continue;
            }
            if ($onlyAvailable && !$available) {
                continue;
            }
            if ($query !== '' && !str_contains(mb_strtolower($p['name']), $query)) {
                continue;
            }

            $p['available'] = $available ? 1 : 0;
            $results[] = $p;
        }

        usort($results, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $results;
    }

    public static function realTeams(): array
    {
        $teams = [];
        foreach (self::listAll() as $p) {
            $teams[$p['real_team']] = true;
        }
        $list = array_keys($teams);
        sort($list);
        return $list;
    }
}
