<?php
/**
 * Gestione dei fantateam (rose configurate dagli utenti).
 */

declare(strict_types=1);

final class TeamService
{
    public static function createTeam(int $userId, string $name, string $coachName = '', string $logo = ''): array
    {
        $row = CsvStorage::append(Schema::TEAMS, Schema::TEAMS_HEADERS, [
            'user_id' => (string)$userId,
            'name' => $name,
            'coach_name' => $coachName,
            'logo' => $logo,
            'created_at' => now(),
            'updated_at' => now(),
            'active' => '1',
        ]);
        AuditService::log('CREATE_TEAM', null, null, (int)$row['id'], null, null, $name);
        return $row;
    }

    public static function updateTeam(int $teamId, array $data): void
    {
        $previous = self::getTeamById($teamId);
        CsvStorage::update(Schema::TEAMS, Schema::TEAMS_HEADERS,
            fn($t) => (int)$t['id'] === $teamId,
            function ($t) use ($data) {
                foreach (['name', 'coach_name', 'logo'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $t[$field] = $data[$field];
                    }
                }
                $t['updated_at'] = now();
                return $t;
            }
        );
        AuditService::log('UPDATE_TEAM', null, null, $teamId,
            null, $previous['name'] ?? '', $data['name'] ?? ($previous['name'] ?? ''));
    }

    public static function getTeamById(int $teamId): ?array
    {
        return CsvStorage::findOne(Schema::TEAMS, Schema::TEAMS_HEADERS, fn($t) => (int)$t['id'] === $teamId);
    }

    /**
     * Restituisce la squadra attiva più recente dell'utente (un utente ha
     * tipicamente un solo fantateam, riutilizzabile su più aste).
     */
    public static function getTeamByUser(int $userId): ?array
    {
        $teams = CsvStorage::findAll(Schema::TEAMS, Schema::TEAMS_HEADERS,
            fn($t) => (int)$t['user_id'] === $userId && (int)$t['active'] === 1
        );
        if (empty($teams)) {
            return null;
        }
        usort($teams, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return $teams[0];
    }

    public static function listAllTeams(): array
    {
        return CsvStorage::readAll(Schema::TEAMS, Schema::TEAMS_HEADERS);
    }

    /**
     * Squadre associate a un'asta (join auction_teams + teams), solo enabled=1.
     */
    public static function getAuctionTeams(int $auctionId): array
    {
        $links = CsvStorage::findAll(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS,
            fn($l) => (int)$l['auction_id'] === $auctionId && (int)$l['enabled'] === 1
        );
        $teams = [];
        foreach ($links as $link) {
            $team = self::getTeamById((int)$link['team_id']);
            if ($team !== null) {
                $team['joined_at'] = $link['joined_at'];
                $team['auction_team_id'] = $link['id'];
                $teams[] = $team;
            }
        }
        return $teams;
    }

    public static function getAuctionLink(int $auctionId, int $teamId): ?array
    {
        return CsvStorage::findOne(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS,
            fn($l) => (int)$l['auction_id'] === $auctionId && (int)$l['team_id'] === $teamId
        );
    }

    /**
     * Trova l'asta (se unica) a cui una squadra è attualmente associata e abilitata.
     * Restituisce l'array dei link (una squadra potrebbe partecipare a più aste).
     */
    public static function getTeamAuctionLinks(int $teamId): array
    {
        return CsvStorage::findAll(Schema::AUCTION_TEAMS, Schema::AUCTION_TEAMS_HEADERS,
            fn($l) => (int)$l['team_id'] === $teamId && (int)$l['enabled'] === 1
        );
    }
}
