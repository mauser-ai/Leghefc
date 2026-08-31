<?php
/**
 * Logica di business dell'asta: creazione/gestione aste, acquisti,
 * annullamenti, svincoli, modifiche, calcolo budget e stato live.
 *
 * Le operazioni che modificano lo stato dell'asta (buyPlayer, undoPurchase,
 * releasePlayer, updatePurchase) sono racchiuse in un lock esclusivo per
 * asta (file in /data/locks) per garantire che l'intera sequenza
 * "leggi stato -> valida -> scrivi" sia atomica anche attraverso più file
 * CSV (purchases.csv + auction_players.csv + audit.csv).
 */

declare(strict_types=1);

final class AuctionService
{
    // ---------------------------------------------------------------
    // CRUD Aste
    // ---------------------------------------------------------------

    public static function listAll(): array
    {
        $rows = CsvStorage::readAll(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS);
        usort($rows, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return $rows;
    }

    public static function findById(int $id): ?array
    {
        return CsvStorage::findOne(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS, fn($a) => (int)$a['id'] === $id);
    }

    public static function findByInviteCode(string $code): ?array
    {
        $code = trim($code);
        return CsvStorage::findOne(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS,
            fn($a) => strcasecmp($a['invite_code'], $code) === 0
        );
    }

    public static function createAuction(array $data): array
    {
        $row = CsvStorage::append(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS, [
            'name' => $data['name'],
            'invite_code' => strtoupper($data['invite_code']),
            'status' => Schema::STATUS_DRAFT,
            'auction_date' => $data['auction_date'] ?? '',
            'initial_budget' => (string)(int)$data['initial_budget'],
            'goalkeepers' => (string)(int)$data['goalkeepers'],
            'defenders' => (string)(int)$data['defenders'],
            'midfielders' => (string)(int)$data['midfielders'],
            'attackers' => (string)(int)$data['attackers'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PlayerService::ensureAuctionPlayers((int)$row['id']);
        AuditService::log('CREATE_AUCTION', (int)$row['id'], null, null, null, null, $row['name']);
        BackupService::snapshot('create_auction');
        return $row;
    }

    public static function updateAuction(int $id, array $data): void
    {
        CsvStorage::update(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS,
            fn($a) => (int)$a['id'] === $id,
            function ($a) use ($data) {
                foreach (['name', 'invite_code', 'auction_date', 'initial_budget', 'goalkeepers', 'defenders', 'midfielders', 'attackers'] as $f) {
                    if (array_key_exists($f, $data)) {
                        $a[$f] = $f === 'invite_code' ? strtoupper($data[$f]) : $data[$f];
                    }
                }
                $a['updated_at'] = now();
                return $a;
            }
        );
        AuditService::log('UPDATE_AUCTION', $id);
        BackupService::snapshot('update_auction');
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, Schema::STATUSES, true)) {
            return false;
        }
        $auction = self::findById($id);
        if ($auction === null) {
            return false;
        }
        $previous = $auction['status'];
        CsvStorage::update(Schema::AUCTIONS, Schema::AUCTIONS_HEADERS,
            fn($a) => (int)$a['id'] === $id,
            function ($a) use ($status) {
                $a['status'] = $status;
                $a['updated_at'] = now();
                return $a;
            }
        );
        AuditService::log('CHANGE_STATUS', $id, null, null, null, $previous, $status);
        BackupService::snapshot('status_change_' . $status);
        return true;
    }

    // ---------------------------------------------------------------
    // Partecipazione
    // ---------------------------------------------------------------

    public static function joinAuction(int $auctionId, int $teamId): array
    {
        $existing = TeamService::getAuctionLink($auctionId, $teamId);
        if ($existing !== null) {
            if ((int)$existing['enabled'] !== 1) {
                CsvStorage::update(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS,
                    fn($l) => (int)$l['id'] === (int)$existing['id'],
                    function ($l) {
                        $l['enabled'] = '1';
                        $l['joined_at'] = now();
                        return $l;
                    }
                );
            }
            AuditService::log('JOIN_AUCTION', $auctionId, null, $teamId);
            return ['ok' => true];
        }

        CsvStorage::append(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS, [
            'auction_id' => (string)$auctionId,
            'team_id' => (string)$teamId,
            'enabled' => '1',
            'joined_at' => now(),
        ]);
        PlayerService::ensureAuctionPlayers($auctionId);
        AuditService::log('JOIN_AUCTION', $auctionId, null, $teamId);
        return ['ok' => true];
    }

    public static function leaveAuction(int $auctionId, int $teamId): void
    {
        CsvStorage::update(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS,
            fn($l) => (int)$l['auction_id'] === $auctionId && (int)$l['team_id'] === $teamId,
            function ($l) {
                $l['enabled'] = '0';
                return $l;
            }
        );
        AuditService::log('LEAVE_AUCTION', $auctionId, null, $teamId);
    }

    // ---------------------------------------------------------------
    // Calcoli budget / rosa
    // ---------------------------------------------------------------

    public static function getActivePurchases(int $auctionId, ?int $teamId = null): array
    {
        return CsvStorage::findAll(Schema::PURCHASES, Schema::PURCHASES_HEADERS, function ($p) use ($auctionId, $teamId) {
            if ((int)$p['auction_id'] !== $auctionId || (int)$p['active'] !== 1) {
                return false;
            }
            return $teamId === null || (int)$p['team_id'] === $teamId;
        });
    }

    /**
     * True se esiste almeno un acquisto attivo in una qualsiasi asta (usato per
     * bloccare reimport del listone che romperebbero i riferimenti già acquistati).
     */
    public static function hasAnyActivePurchase(): bool
    {
        foreach (CsvStorage::readAll(Schema::PURCHASES, Schema::PURCHASES_HEADERS) as $p) {
            if ((int)$p['active'] === 1) {
                return true;
            }
        }
        return false;
    }

    public static function getRemainingBudget(array $auction, int $teamId): int
    {
        $spent = 0;
        foreach (self::getActivePurchases((int)$auction['id'], $teamId) as $p) {
            $spent += (int)$p['price'];
        }
        return (int)$auction['initial_budget'] - $spent;
    }

    public static function getSquadSize(array $auction): int
    {
        return (int)$auction['goalkeepers'] + (int)$auction['defenders'] + (int)$auction['midfielders'] + (int)$auction['attackers'];
    }

    public static function getRoleLimit(array $auction, string $role): int
    {
        return match ($role) {
            Schema::ROLE_GK => (int)$auction['goalkeepers'],
            Schema::ROLE_DEF => (int)$auction['defenders'],
            Schema::ROLE_MID => (int)$auction['midfielders'],
            Schema::ROLE_ATT => (int)$auction['attackers'],
            default => 0,
        };
    }

    /**
     * Rosa (acquisti attivi + dati giocatore) di una squadra in un'asta.
     */
    public static function getTeamRoster(int $auctionId, int $teamId): array
    {
        $purchases = self::getActivePurchases($auctionId, $teamId);
        usort($purchases, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        $roster = [];
        foreach ($purchases as $p) {
            $player = PlayerService::findById((int)$p['player_id']);
            $roster[] = [
                'purchase_id' => (int)$p['id'],
                'player_id' => (int)$p['player_id'],
                'name' => $player['name'] ?? '???',
                'real_team' => $player['real_team'] ?? '',
                'role' => $player['role'] ?? '',
                'quotation' => $player['quotation'] ?? '',
                'fvm' => $player['fvm'] ?? '',
                'price' => (int)$p['price'],
                'timestamp' => $p['timestamp'],
            ];
        }
        return $roster;
    }

    public static function getRoleCounts(int $auctionId, int $teamId): array
    {
        $counts = [Schema::ROLE_GK => 0, Schema::ROLE_DEF => 0, Schema::ROLE_MID => 0, Schema::ROLE_ATT => 0];
        foreach (self::getTeamRoster($auctionId, $teamId) as $r) {
            if (isset($counts[$r['role']])) {
                $counts[$r['role']]++;
            }
        }
        return $counts;
    }

    /**
     * Offerta massima consentita per una squadra: crediti residui meno i
     * posti ancora da riempire dopo l'acquisto corrente (per garantire la
     * possibilità matematica di completare la rosa).
     */
    public static function getMaximumBid(array $auction, int $auctionId, int $teamId): int
    {
        $remainingBudget = self::getRemainingBudget($auction, $teamId);
        $owned = count(self::getTeamRoster($auctionId, $teamId));
        $totalSlots = self::getSquadSize($auction);
        $slotsRemaining = max(0, $totalSlots - $owned);

        if ($slotsRemaining <= 0) {
            return 0; // rosa già completa
        }

        $slotsAfterThisOne = $slotsRemaining - 1;
        $max = $remainingBudget - $slotsAfterThisOne;
        return max(0, $max);
    }

    public static function getLastPurchase(int $auctionId): ?array
    {
        $purchases = self::getActivePurchases($auctionId);
        if (empty($purchases)) {
            return null;
        }
        usort($purchases, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        $p = $purchases[0];
        $player = PlayerService::findById((int)$p['player_id']);
        $team = TeamService::getTeamById((int)$p['team_id']);
        return [
            'purchase_id' => (int)$p['id'],
            'player_name' => $player['name'] ?? '???',
            'player_role' => $player['role'] ?? '',
            'team_name' => $team['name'] ?? '???',
            'team_id' => (int)$p['team_id'],
            'price' => (int)$p['price'],
            'timestamp' => $p['timestamp'],
        ];
    }

    // ---------------------------------------------------------------
    // Giocatore attualmente all'asta
    // ---------------------------------------------------------------

    public static function getCurrentPlayer(int $auctionId): ?array
    {
        $row = CsvStorage::findOne(Schema::CURRENT_AUCTION, Schema::CURRENT_AUCTION_HEADERS,
            fn($r) => (int)$r['auction_id'] === $auctionId
        );
        if ($row === null || $row['player_id'] === '') {
            return null;
        }
        $player = PlayerService::findById((int)$row['player_id']);
        if ($player === null) {
            return null;
        }
        $player['updated_at'] = $row['updated_at'];
        return $player;
    }

    public static function setCurrentPlayer(int $auctionId, ?int $playerId): void
    {
        $updated = CsvStorage::update(Schema::CURRENT_AUCTION, Schema::CURRENT_AUCTION_HEADERS,
            fn($r) => (int)$r['auction_id'] === $auctionId,
            function ($r) use ($playerId) {
                $r['player_id'] = $playerId !== null ? (string)$playerId : '';
                $r['updated_at'] = now();
                return $r;
            }
        );
        if ($updated === 0) {
            CsvStorage::append(Schema::CURRENT_AUCTION, Schema::CURRENT_AUCTION_HEADERS, [
                'auction_id' => (string)$auctionId,
                'player_id' => $playerId !== null ? (string)$playerId : '',
                'updated_at' => now(),
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Lock per asta
    // ---------------------------------------------------------------

    private static function withAuctionLock(int $auctionId, callable $fn): mixed
    {
        $path = LOCK_DIR . '/auction_' . $auctionId . '.lock';
        $fh = fopen($path, 'c');
        if ($fh === false) {
            throw new RuntimeException('Impossibile acquisire il lock asta.');
        }
        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // ---------------------------------------------------------------
    // Acquisto
    // ---------------------------------------------------------------

    /**
     * Esegue l'acquisto di un giocatore con tutte le validazioni richieste.
     * Restituisce ['success'=>true,'purchase'=>...] o ['success'=>false,'error'=>...].
     */
    public static function buyPlayer(int $auctionId, int $playerId, int $teamId, int $price): array
    {
        return self::withAuctionLock($auctionId, function () use ($auctionId, $playerId, $teamId, $price) {
            $auction = self::findById($auctionId);
            if ($auction === null) {
                return ['success' => false, 'error' => 'Asta non trovata.'];
            }
            if ($auction['status'] !== Schema::STATUS_LIVE) {
                return ['success' => false, 'error' => "L'asta non è LIVE."];
            }

            $player = PlayerService::findById($playerId);
            if ($player === null) {
                return ['success' => false, 'error' => 'Giocatore non trovato.'];
            }

            $link = TeamService::getAuctionLink($auctionId, $teamId);
            if ($link === null || (int)$link['enabled'] !== 1) {
                return ['success' => false, 'error' => 'La squadra non partecipa a questa asta.'];
            }

            if (!PlayerService::isAvailable($auctionId, $playerId)) {
                return ['success' => false, 'error' => 'Giocatore non disponibile (già acquistato).'];
            }

            if ($price < 1) {
                return ['success' => false, 'error' => 'Il prezzo deve essere almeno 1.'];
            }

            $roleCounts = self::getRoleCounts($auctionId, $teamId);
            $roleLimit = self::getRoleLimit($auction, $player['role']);
            if ($roleCounts[$player['role']] >= $roleLimit) {
                return ['success' => false, 'error' => 'Limite di ruolo raggiunto (' . Schema::ROLE_LABELS[$player['role']] . ').'];
            }

            $remainingBudget = self::getRemainingBudget($auction, $teamId);
            if ($price > $remainingBudget) {
                return ['success' => false, 'error' => 'Crediti insufficienti.'];
            }

            $maxBid = self::getMaximumBid($auction, $auctionId, $teamId);
            if ($price > $maxBid) {
                return ['success' => false, 'error' => "Offerta superiore al massimo spendibile ($maxBid crediti), servono crediti per completare la rosa."];
            }

            $purchase = CsvStorage::append(Schema::PURCHASES, Schema::PURCHASES_HEADERS, [
                'auction_id' => (string)$auctionId,
                'player_id' => (string)$playerId,
                'team_id' => (string)$teamId,
                'price' => (string)$price,
                'timestamp' => now(),
                'active' => '1',
            ]);

            PlayerService::setAvailability($auctionId, $playerId, false);
            self::setCurrentPlayer($auctionId, null);

            AuditService::log('BUY', $auctionId, $playerId, $teamId, (string)$price, null, $player['name']);
            BackupService::snapshot('buy_' . $player['name']);

            return ['success' => true, 'purchase' => $purchase];
        });
    }

    public static function undoPurchase(int $auctionId, int $purchaseId): array
    {
        return self::withAuctionLock($auctionId, function () use ($auctionId, $purchaseId) {
            return self::deactivatePurchase($auctionId, $purchaseId, 'UNDO');
        });
    }

    public static function releasePlayer(int $auctionId, int $purchaseId): array
    {
        return self::withAuctionLock($auctionId, function () use ($auctionId, $purchaseId) {
            return self::deactivatePurchase($auctionId, $purchaseId, 'RELEASE');
        });
    }

    public static function undoLastPurchase(int $auctionId): array
    {
        return self::withAuctionLock($auctionId, function () use ($auctionId) {
            $last = self::getLastPurchase($auctionId);
            if ($last === null) {
                return ['success' => false, 'error' => 'Nessun acquisto da annullare.'];
            }
            return self::deactivatePurchase($auctionId, $last['purchase_id'], 'UNDO');
        });
    }

    private static function deactivatePurchase(int $auctionId, int $purchaseId, string $action): array
    {
        $purchase = CsvStorage::findOne(Schema::PURCHASES, Schema::PURCHASES_HEADERS,
            fn($p) => (int)$p['id'] === $purchaseId && (int)$p['auction_id'] === $auctionId
        );
        if ($purchase === null || (int)$purchase['active'] !== 1) {
            return ['success' => false, 'error' => 'Acquisto non trovato o già annullato.'];
        }

        CsvStorage::update(Schema::PURCHASES, Schema::PURCHASES_HEADERS,
            fn($p) => (int)$p['id'] === $purchaseId,
            function ($p) {
                $p['active'] = '0';
                return $p;
            }
        );
        PlayerService::setAvailability($auctionId, (int)$purchase['player_id'], true);

        $player = PlayerService::findById((int)$purchase['player_id']);
        AuditService::log($action, $auctionId, (int)$purchase['player_id'], (int)$purchase['team_id'],
            $purchase['price'], $purchase['price'], null);
        BackupService::snapshot(strtolower($action) . '_' . ($player['name'] ?? ''));

        return ['success' => true];
    }

    /**
     * Modifica un acquisto esistente (prezzo, squadra e/o giocatore), con
     * rivalidazione completa di budget, limiti e disponibilità.
     */
    public static function updatePurchase(int $auctionId, int $purchaseId, ?int $newPrice, ?int $newTeamId, ?int $newPlayerId): array
    {
        return self::withAuctionLock($auctionId, function () use ($auctionId, $purchaseId, $newPrice, $newTeamId, $newPlayerId) {
            $auction = self::findById($auctionId);
            $purchase = CsvStorage::findOne(Schema::PURCHASES, Schema::PURCHASES_HEADERS,
                fn($p) => (int)$p['id'] === $purchaseId && (int)$p['auction_id'] === $auctionId
            );
            if ($auction === null || $purchase === null || (int)$purchase['active'] !== 1) {
                return ['success' => false, 'error' => 'Acquisto non trovato.'];
            }

            $price = $newPrice ?? (int)$purchase['price'];
            $teamId = $newTeamId ?? (int)$purchase['team_id'];
            $playerId = $newPlayerId ?? (int)$purchase['player_id'];

            if ($price < 1) {
                return ['success' => false, 'error' => 'Il prezzo deve essere almeno 1.'];
            }

            $player = PlayerService::findById($playerId);
            if ($player === null) {
                return ['success' => false, 'error' => 'Giocatore non trovato.'];
            }

            $playerChanged = $playerId !== (int)$purchase['player_id'];
            if ($playerChanged && !PlayerService::isAvailable($auctionId, $playerId)) {
                return ['success' => false, 'error' => 'Il nuovo giocatore non è disponibile.'];
            }

            // Budget/limiti ricalcolati escludendo la riga corrente (verrà sostituita).
            $spentOthers = 0;
            $roleCountsOthers = [Schema::ROLE_GK => 0, Schema::ROLE_DEF => 0, Schema::ROLE_MID => 0, Schema::ROLE_ATT => 0];
            foreach (self::getActivePurchases($auctionId, $teamId) as $p) {
                if ((int)$p['id'] === $purchaseId) {
                    continue;
                }
                $spentOthers += (int)$p['price'];
                $otherPlayer = PlayerService::findById((int)$p['player_id']);
                if ($otherPlayer !== null && isset($roleCountsOthers[$otherPlayer['role']])) {
                    $roleCountsOthers[$otherPlayer['role']]++;
                }
            }

            $roleLimit = self::getRoleLimit($auction, $player['role']);
            if ($roleCountsOthers[$player['role']] >= $roleLimit) {
                return ['success' => false, 'error' => 'Limite di ruolo raggiunto (' . Schema::ROLE_LABELS[$player['role']] . ').'];
            }

            $remainingBudget = (int)$auction['initial_budget'] - $spentOthers;
            if ($price > $remainingBudget) {
                return ['success' => false, 'error' => 'Crediti insufficienti per la squadra selezionata.'];
            }

            $previousPrice = $purchase['price'];
            $previousTeam = $purchase['team_id'];

            if ($playerChanged) {
                PlayerService::setAvailability($auctionId, (int)$purchase['player_id'], true);
                PlayerService::setAvailability($auctionId, $playerId, false);
            }

            CsvStorage::update(Schema::PURCHASES, Schema::PURCHASES_HEADERS,
                fn($p) => (int)$p['id'] === $purchaseId,
                function ($p) use ($price, $teamId, $playerId) {
                    $p['price'] = (string)$price;
                    $p['team_id'] = (string)$teamId;
                    $p['player_id'] = (string)$playerId;
                    return $p;
                }
            );

            if ($newPrice !== null) {
                AuditService::log('UPDATE_PRICE', $auctionId, $playerId, $teamId, (string)$price, (string)$previousPrice, (string)$price);
            }
            if ($newTeamId !== null) {
                AuditService::log('UPDATE_TEAM', $auctionId, $playerId, $teamId, (string)$price, (string)$previousTeam, (string)$teamId);
            }
            BackupService::snapshot('update_purchase_' . $purchaseId);

            return ['success' => true];
        });
    }

    // ---------------------------------------------------------------
    // Stato aggregato (per API state/display/dashboard)
    // ---------------------------------------------------------------

    public static function getAuctionState(int $auctionId): ?array
    {
        $auction = self::findById($auctionId);
        if ($auction === null) {
            return null;
        }

        $teams = [];
        foreach (TeamService::getAuctionTeams($auctionId) as $team) {
            $teamId = (int)$team['id'];
            $roster = self::getTeamRoster($auctionId, $teamId);
            $roleCounts = self::getRoleCounts($auctionId, $teamId);
            $remaining = self::getRemainingBudget($auction, $teamId);

            $teams[] = [
                'team_id' => $teamId,
                'name' => $team['name'],
                'coach_name' => $team['coach_name'],
                'logo' => $team['logo'],
                'remaining_budget' => $remaining,
                'spent' => (int)$auction['initial_budget'] - $remaining,
                'players_count' => count($roster),
                'role_counts' => $roleCounts,
                'role_limits' => [
                    Schema::ROLE_GK => (int)$auction['goalkeepers'],
                    Schema::ROLE_DEF => (int)$auction['defenders'],
                    Schema::ROLE_MID => (int)$auction['midfielders'],
                    Schema::ROLE_ATT => (int)$auction['attackers'],
                ],
                'max_bid' => self::getMaximumBid($auction, $auctionId, $teamId),
                'roster' => $roster,
            ];
        }

        return [
            'auction' => $auction,
            'teams' => $teams,
            'current_player' => self::getCurrentPlayer($auctionId),
            'last_purchase' => self::getLastPurchase($auctionId),
            'timestamp' => now(),
        ];
    }
}
