-- Schema MySQL per Fantacalcio Asta Manager.
-- Sostituisce lo storage CSV con tabelle equivalenti: stessi nomi di colonna
-- degli header CSV originali, così tutto il codice applicativo (che lavora
-- per array associativi "colonna => valore stringa") resta invariato.
--
-- Ogni tabella ha una colonna "id" auto-incrementale usata come chiave
-- primaria e per mantenere un ordine di lettura stabile (equivalente
-- all'ordine di append nel vecchio file CSV). Per le tabelle il cui CSV
-- originale non aveva una colonna "id" (auction_players, settings,
-- current_auction, audit) la colonna è comunque presente ma è un dettaglio
-- interno: l'applicazione non la legge né la scrive mai.
--
-- Tutte le colonne dati (comprese quelle che contengono id di riferimento ad
-- altre tabelle, es. user_id/team_id/auction_id/player_id) sono VARCHAR e
-- non INT: l'applicazione tratta da sempre ogni valore come stringa (fa lei
-- i cast (int)/(string) dove serve) e in alcuni casi scrive esplicitamente
-- una stringa vuota '' per "nessun valore" — cosa che MySQL in modalità
-- strict rifiuterebbe in una colonna numerica. Solo la vera chiave primaria
-- "id" di ogni tabella è un intero.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nickname VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at VARCHAR(30) NOT NULL,
  last_login VARCHAR(30) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_nickname (nickname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  coach_name VARCHAR(150) NULL,
  logo VARCHAR(255) NULL,
  created_at VARCHAR(30) NOT NULL,
  updated_at VARCHAR(30) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auctions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  invite_code VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL,
  auction_date VARCHAR(20) NULL,
  initial_budget VARCHAR(20) NOT NULL,
  goalkeepers VARCHAR(10) NOT NULL,
  defenders VARCHAR(10) NOT NULL,
  midfielders VARCHAR(10) NOT NULL,
  attackers VARCHAR(10) NOT NULL,
  created_at VARCHAR(30) NOT NULL,
  updated_at VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_invite_code (invite_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auction_teams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id VARCHAR(20) NOT NULL,
  team_id VARCHAR(20) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  joined_at VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_auction_id (auction_id),
  KEY idx_team_id (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  real_team VARCHAR(100) NULL,
  role VARCHAR(5) NOT NULL,
  quotation VARCHAR(20) NULL,
  fvm VARCHAR(20) NULL,
  external_id VARCHAR(20) NULL,
  PRIMARY KEY (id),
  KEY idx_role (role),
  KEY idx_external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auction_players (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id VARCHAR(20) NOT NULL,
  player_id VARCHAR(20) NOT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_auction_player (auction_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id VARCHAR(20) NOT NULL,
  player_id VARCHAR(20) NOT NULL,
  team_id VARCHAR(20) NOT NULL,
  price VARCHAR(20) NOT NULL,
  timestamp VARCHAR(30) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_auction_active (auction_id, active),
  KEY idx_team_id (team_id),
  KEY idx_player_id (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS current_auction (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id VARCHAR(20) NOT NULL,
  player_id VARCHAR(20) NULL,
  updated_at VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_auction_id (auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  timestamp VARCHAR(30) NOT NULL,
  user_id VARCHAR(20) NULL,
  action VARCHAR(50) NOT NULL,
  auction_id VARCHAR(20) NULL,
  player_id VARCHAR(20) NULL,
  team_id VARCHAR(20) NULL,
  price VARCHAR(20) NULL,
  previous_value TEXT NULL,
  new_value TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_auction_id (auction_id),
  KEY idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
