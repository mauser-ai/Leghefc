<?php
/**
 * Definizione centralizzata dei nomi file e delle intestazioni colonna
 * di tutti i CSV usati come storage dell'applicazione.
 */

declare(strict_types=1);

final class Schema
{
    public const USERS = 'users.csv';
    public const USERS_HEADERS = ['id', 'nickname', 'password_hash', 'created_at', 'last_login', 'active', 'role'];

    public const TEAMS = 'teams.csv';
    public const TEAMS_HEADERS = ['id', 'user_id', 'name', 'coach_name', 'logo', 'created_at', 'updated_at', 'active'];

    public const AUCTIONS = 'auctions.csv';
    public const AUCTIONS_HEADERS = [
        'id', 'name', 'invite_code', 'status', 'auction_date', 'initial_budget',
        'goalkeepers', 'defenders', 'midfielders', 'attackers', 'created_at', 'updated_at',
    ];

    public const AUCTION_TEAMS = 'auction_teams.csv';
    public const AUCTION_TEAMS_HEADERS = ['id', 'auction_id', 'team_id', 'enabled', 'joined_at'];

    public const PLAYERS = 'players.csv';
    // external_id: id ufficiale del giocatore su fantacalcio.it (colonna "Id" del
    // template Quotazioni Fantacalcio), usato per costruire l'URL dell'avatar/card.
    public const PLAYERS_HEADERS = ['id', 'name', 'real_team', 'role', 'quotation', 'fvm', 'external_id'];

    public const AUCTION_PLAYERS = 'auction_players.csv';
    public const AUCTION_PLAYERS_HEADERS = ['auction_id', 'player_id', 'available'];

    public const PURCHASES = 'purchases.csv';
    public const PURCHASES_HEADERS = ['id', 'auction_id', 'player_id', 'team_id', 'price', 'timestamp', 'active'];

    public const SETTINGS = 'settings.csv';
    public const SETTINGS_HEADERS = ['key', 'value'];

    public const CURRENT_AUCTION = 'current_auction.csv';
    public const CURRENT_AUCTION_HEADERS = ['auction_id', 'player_id', 'updated_at'];

    public const AUDIT = 'audit.csv';
    public const AUDIT_HEADERS = [
        'timestamp', 'user_id', 'action', 'auction_id', 'player_id', 'team_id',
        'price', 'previous_value', 'new_value',
    ];

    // Ruoli giocatore
    public const ROLE_GK = 'P'; // Portiere
    public const ROLE_DEF = 'D'; // Difensore
    public const ROLE_MID = 'C'; // Centrocampista
    public const ROLE_ATT = 'A'; // Attaccante

    public const ROLES = [self::ROLE_GK, self::ROLE_DEF, self::ROLE_MID, self::ROLE_ATT];

    public const ROLE_LABELS = [
        self::ROLE_GK => 'Portiere',
        self::ROLE_DEF => 'Difensore',
        self::ROLE_MID => 'Centrocampista',
        self::ROLE_ATT => 'Attaccante',
    ];

    // Status asta
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_LIVE = 'LIVE';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_LIVE,
        self::STATUS_COMPLETED, self::STATUS_ARCHIVED,
    ];
}
