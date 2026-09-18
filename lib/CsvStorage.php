<?php
/**
 * CsvStorage centralizza tutto l'accesso ai dati dell'applicazione. Nome
 * storico dai tempi dello storage su file CSV: da quando l'app usa MySQL,
 * ogni "file" (es. "users.csv") è semplicemente il nome della tabella
 * corrispondente (es. "users"), e ogni riga è un array associativo
 * colonna => valore stringa, esattamente come prima. Nessun'altra parte del
 * codice deve interrogare il database direttamente: tutte le pagine e i
 * servizi passano da qui, che è anche l'unico file che è dovuto cambiare
 * nella migrazione da CSV a database.
 *
 * Garantisce:
 *  - letture/scritture atomiche tramite transazioni MySQL con lock di riga
 *  - append/update mirati (una singola query), non una riscrittura
 *    dell'intera tabella ad ogni chiamata
 *  - una cache in memoria per la durata della singola richiesta HTTP, per
 *    evitare di interrogare più volte lo stesso dato nello stesso ciclo
 */

declare(strict_types=1);

class CsvStorage
{
    /**
     * Cache in memoria per la durata della singola richiesta HTTP: evita di
     * interrogare più volte la stessa tabella nello stesso ciclo (es.
     * players.csv/purchases.csv letti decine di volte per comporre le rose
     * di tutte le squadre ad ogni poll di ogni partecipante). Invalidata
     * automaticamente ad ogni scrittura sulla stessa tabella, quindi non può
     * mai restituire dati non più validi all'interno della stessa richiesta.
     */
    private static $cache = [];

    /**
     * Nome tabella a partire dal nome file storico (es. "users.csv" -> "users").
     */
    private static function table(string $filename): string
    {
        return preg_replace('/\.csv$/', '', $filename);
    }

    private static function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Compatibilità con il vecchio storage su file: restituisce il nome
     * tabella. Usato solo da script di sviluppo locali (scripts/seed_demo.php).
     */
    public static function path(string $filename): string
    {
        return self::table($filename);
    }

    /**
     * Compatibilità con il vecchio storage su file: con MySQL le tabelle
     * sono create in anticipo da sql/schema.sql / scripts/migrate_to_db.php,
     * quindi qui non c'è nulla da fare.
     */
    public static function ensure(string $filename, array $headers): void
    {
        // no-op
    }

    /**
     * Legge tutte le righe come array associativi (chiave = nome colonna),
     * usando la cache di richiesta quando disponibile.
     */
    public static function readAll(string $filename, array $headers): array
    {
        if (isset(self::$cache[$filename])) {
            return self::$cache[$filename];
        }

        $table = self::table($filename);
        $cols = implode(', ', array_map([self::class, 'quoteIdent'], $headers));
        $stmt = Database::connection()->query(
            "SELECT {$cols} FROM " . self::quoteIdent($table) . " ORDER BY id ASC"
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $data) {
            $row = [];
            foreach ($headers as $col) {
                $row[$col] = $data[$col] ?? '';
            }
            $rows[] = $row;
        }

        self::$cache[$filename] = $rows;
        return $rows;
    }

    /**
     * Come readAll(), ma dentro una transazione con lock di riga (SELECT ...
     * FOR UPDATE) e include la colonna interna "id" reale della tabella
     * (che può non coincidere con nessuna colonna esposta, per le tabelle il
     * cui CSV originale non aveva una colonna "id" propria: auction_players,
     * settings, current_auction, audit). Usata solo internamente per le
     * scritture mirate (update/delete per riga) e per transaction().
     */
    private static function readAllForUpdate(PDO $pdo, string $table, array $headers): array
    {
        $cols = implode(', ', array_map([self::class, 'quoteIdent'], $headers));
        $stmt = $pdo->query(
            "SELECT id AS __row_id, {$cols} FROM " . self::quoteIdent($table) . " ORDER BY id ASC FOR UPDATE"
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $data) {
            $row = ['__row_id' => (string)$data['__row_id']];
            foreach ($headers as $col) {
                $row[$col] = $data[$col] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function stripRowId(array $row): array
    {
        unset($row['__row_id']);
        return $row;
    }

    /**
     * Sostituisce l'intero contenuto della tabella (usato per il caricamento
     * in blocco del listone giocatori): cancella tutte le righe e reinserisce
     * quelle passate, in un'unica transazione.
     */
    public static function writeAll(string $filename, array $rows, array $headers): void
    {
        $table = self::table($filename);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM ' . self::quoteIdent($table));
            self::bulkInsert($pdo, $table, $rows, $headers);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Non riusa $rows come nuova cache: se una riga aveva l'id vuoto per
        // farlo auto-assegnare, $rows non rispecchierebbe l'id reale appena
        // scritto. Una successiva readAll() nella stessa richiesta rilegge
        // dal database invece di fidarsi di un valore potenzialmente sbagliato.
        unset(self::$cache[$filename]);
    }

    private static function bulkInsert(PDO $pdo, string $table, array $rows, array $headers): void
    {
        if ($rows === []) {
            return;
        }
        $hasId = in_array('id', $headers, true);
        $insertCols = $headers;
        $colList = implode(', ', array_map([self::class, 'quoteIdent'], $insertCols));
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::quoteIdent($table) . " ({$colList}) VALUES ({$placeholders})"
        );
        foreach ($rows as $row) {
            $values = [];
            foreach ($insertCols as $col) {
                $values[] = $row[$col] ?? '';
            }
            // Se la colonna "id" è vuota, lascia che sia MySQL ad auto-incrementarla:
            // un valore vuoto in una colonna AUTO_INCREMENT non è accettato come "0".
            if ($hasId) {
                $idPos = array_search('id', $insertCols, true);
                if (($values[$idPos] ?? '') === '') {
                    $values[$idPos] = null;
                }
            }
            $stmt->execute($values);
        }
    }

    /**
     * Transazione read-modify-write generica: $mutator riceve l'array di
     * righe correnti (con lock di riga) e deve restituire il nuovo array di
     * righe da salvare, oppure null per abortire senza scrivere. Usata solo
     * dalle chiamate dirette con logica di mutazione arbitraria
     * (registrazione utente, sincronizzazione disponibilità giocatori): non
     * è nel percorso a alta frequenza (polling), quindi la riscrittura
     * completa della tabella qui non è un problema di prestazioni.
     */
    public static function transaction(string $filename, array $headers, callable $mutator): mixed
    {
        $table = self::table($filename);
        $pdo = Database::connection();

        $pdo->beginTransaction();
        try {
            $rowsWithId = self::readAllForUpdate($pdo, $table, $headers);
            $rows = array_map([self::class, 'stripRowId'], $rowsWithId);

            $result = $mutator($rows, $headers);
            $newRows = is_array($result) ? $result : (is_array($result['rows'] ?? null) ? $result['rows'] : null);

            if ($newRows !== null) {
                $pdo->exec('DELETE FROM ' . self::quoteIdent($table));
                self::bulkInsert($pdo, $table, $newRows, $headers);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::$cache[$filename] = $newRows ?? $rows;

        return $result;
    }

    /**
     * Aggiunge una riga con un singolo INSERT mirato (non riscrive la
     * tabella): è il percorso usato ad ogni azione loggata nell'audit trail
     * e ad ogni acquisto, quindi deve restare una query sola indipendentemente
     * da quante righe la tabella contiene già.
     * Assegna automaticamente un id incrementale se la colonna "id" è tra gli
     * headers e non è valorizzata. Restituisce la riga effettivamente scritta
     * (con id assegnato).
     */
    public static function append(string $filename, array $headers, array $row): array
    {
        $table = self::table($filename);
        $pdo = Database::connection();
        $hasId = in_array('id', $headers, true);

        $cols = [];
        $values = [];
        foreach ($headers as $col) {
            if ($col === 'id' && (($row['id'] ?? '') === '')) {
                continue; // lascia auto-incrementare
            }
            $cols[] = $col;
            $values[] = $row[$col] ?? '';
        }

        $colList = implode(', ', array_map([self::class, 'quoteIdent'], $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare('INSERT INTO ' . self::quoteIdent($table) . " ({$colList}) VALUES ({$placeholders})");
        $stmt->execute($values);

        $written = $row;
        if ($hasId) {
            $written['id'] = (string)$pdo->lastInsertId();
        }

        unset(self::$cache[$filename]);
        return $written;
    }

    /**
     * Aggiorna le righe che soddisfano $predicate applicando $updater, con un
     * UPDATE mirato per ogni riga modificata (non una riscrittura della
     * tabella). Restituisce il numero di righe modificate.
     */
    public static function update(string $filename, array $headers, callable $predicate, callable $updater): int
    {
        $table = self::table($filename);
        $pdo = Database::connection();
        $count = 0;

        $pdo->beginTransaction();
        try {
            $rowsWithId = self::readAllForUpdate($pdo, $table, $headers);

            $setClause = implode(', ', array_map(fn($c) => self::quoteIdent($c) . ' = ?', $headers));
            $stmt = $pdo->prepare('UPDATE ' . self::quoteIdent($table) . " SET {$setClause} WHERE id = ?");

            foreach ($rowsWithId as $rowWithId) {
                $row = self::stripRowId($rowWithId);
                if (!$predicate($row)) {
                    continue;
                }
                $updated = $updater($row);
                $values = [];
                foreach ($headers as $col) {
                    $values[] = $updated[$col] ?? '';
                }
                $values[] = $rowWithId['__row_id'];
                $stmt->execute($values);
                $count++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        unset(self::$cache[$filename]);
        return $count;
    }

    /**
     * Rimuove fisicamente le righe che soddisfano $predicate (hard delete),
     * con un DELETE mirato per riga. Da usare solo quando il soft-delete
     * (campo active/enabled) non è applicabile.
     */
    public static function delete(string $filename, array $headers, callable $predicate): int
    {
        $table = self::table($filename);
        $pdo = Database::connection();
        $count = 0;

        $pdo->beginTransaction();
        try {
            $rowsWithId = self::readAllForUpdate($pdo, $table, $headers);
            $stmt = $pdo->prepare('DELETE FROM ' . self::quoteIdent($table) . ' WHERE id = ?');

            foreach ($rowsWithId as $rowWithId) {
                $row = self::stripRowId($rowWithId);
                if (!$predicate($row)) {
                    continue;
                }
                $stmt->execute([$rowWithId['__row_id']]);
                $count++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        unset(self::$cache[$filename]);
        return $count;
    }

    /**
     * Trova la prima riga che soddisfa il predicato, oppure null.
     */
    public static function findOne(string $filename, array $headers, callable $predicate): ?array
    {
        foreach (self::readAll($filename, $headers) as $row) {
            if ($predicate($row)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Trova tutte le righe che soddisfano il predicato.
     */
    public static function findAll(string $filename, array $headers, callable $predicate): array
    {
        return array_values(array_filter(
            self::readAll($filename, $headers),
            $predicate
        ));
    }
}
