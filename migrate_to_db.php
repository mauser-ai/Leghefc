<?php
/**
 * Migrazione una tantum: crea le tabelle MySQL (da sql/schema.sql, se
 * mancanti) e importa i dati esistenti dai vecchi file CSV in data/.
 *
 * Da visitare UNA VOLTA dal browser dopo aver caricato config.local.php con
 * le credenziali del database. È idempotente: se una tabella ha già righe
 * non la tocca, a meno di passare ?force=1 nell'URL (che la svuota e la
 * reimporta da capo). Al termine, ELIMINA QUESTO FILE dal server.
 *
 * I vecchi file CSV in data/ NON vengono toccati né cancellati: restano lì
 * come backup, l'app da questo momento in poi legge/scrive solo dal database.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$force = ($_GET['force'] ?? '') === '1';

/**
 * Legge un vecchio file CSV direttamente da disco (bypassa CsvStorage, che
 * da questa migrazione in poi parla solo con il database).
 */
function readOldCsv(string $filename, array $headers): array
{
    $path = DATA_DIR . '/' . $filename;
    if (!is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }
    $rows = [];
    $fileHeaders = fgetcsv($fh, 0, ',', '"', '\\');
    if ($fileHeaders !== false) {
        while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($data) === 1 && $data[0] === null) {
                continue;
            }
            $row = [];
            foreach ($fileHeaders as $i => $col) {
                $row[$col] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
    }
    fclose($fh);

    // Normalizza sulle colonne attese dallo schema corrente.
    $normalized = [];
    foreach ($rows as $row) {
        $out = [];
        foreach ($headers as $col) {
            $out[$col] = $row[$col] ?? '';
        }
        $normalized[] = $out;
    }
    return $normalized;
}

$tables = [
    Schema::USERS => Schema::USERS_HEADERS,
    Schema::TEAMS => Schema::TEAMS_HEADERS,
    Schema::AUCTIONS => Schema::AUCTIONS_HEADERS,
    Schema::AUCTION_TEAMS => Schema::AUCTION_TEAMS_HEADERS,
    Schema::PLAYERS => Schema::PLAYERS_HEADERS,
    Schema::AUCTION_PLAYERS => Schema::AUCTION_PLAYERS_HEADERS,
    Schema::PURCHASES => Schema::PURCHASES_HEADERS,
    Schema::SETTINGS => Schema::SETTINGS_HEADERS,
    Schema::CURRENT_AUCTION => Schema::CURRENT_AUCTION_HEADERS,
    Schema::AUDIT => Schema::AUDIT_HEADERS,
];

$pdo = Database::connection();

echo "=== 1. Creazione tabelle (se mancanti) ===\n";
$schemaSql = file_get_contents(__DIR__ . '/sql/schema.sql');
if ($schemaSql === false) {
    die("ERRORE: impossibile leggere sql/schema.sql\n");
}
// Esegue ogni istruzione CREATE TABLE separatamente (PDO non supporta
// nativamente il multi-statement in modo affidabile su tutti gli host).
foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $stmt) {
    if (stripos($stmt, 'CREATE TABLE') === 0) {
        $pdo->exec($stmt);
    }
}
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Tabelle presenti nel database: " . implode(', ', $existing) . "\n\n";

echo "=== 2. Importazione dati dai CSV ===\n";
foreach ($tables as $file => $headers) {
    $table = preg_replace('/\.csv$/', '', $file);
    $quotedTable = '`' . $table . '`';

    $countStmt = $pdo->query("SELECT COUNT(*) FROM {$quotedTable}");
    $existingCount = (int)$countStmt->fetchColumn();

    if ($existingCount > 0 && !$force) {
        echo "- {$table}: già {$existingCount} righe nel database, salto (usa ?force=1 per reimportare da capo).\n";
        continue;
    }

    $rows = readOldCsv($file, $headers);
    if ($rows === []) {
        echo "- {$table}: nessun dato nel CSV, niente da importare.\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        if ($existingCount > 0) {
            $pdo->exec("DELETE FROM {$quotedTable}");
        }

        $hasId = in_array('id', $headers, true);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $headers));
        $placeholders = implode(', ', array_fill(0, count($headers), '?'));
        $stmt = $pdo->prepare("INSERT INTO {$quotedTable} ({$colList}) VALUES ({$placeholders})");

        $maxId = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $col) {
                $values[] = $row[$col] ?? '';
            }
            $stmt->execute($values);
            if ($hasId) {
                $maxId = max($maxId, (int)($row['id'] ?? 0));
            }
        }

        $pdo->commit();

        if ($hasId && $maxId > 0) {
            // DDL come ALTER TABLE non è transazionale in InnoDB (fa commit
            // implicito): va eseguita a parte, dopo il commit esplicito.
            // Altrimenti il prossimo INSERT con id vuoto (auto-incrementale)
            // ripartirebbe da 1 e collidirebbe con gli id appena importati.
            $pdo->exec("ALTER TABLE {$quotedTable} AUTO_INCREMENT = " . ($maxId + 1));
        }

        echo "- {$table}: importate " . count($rows) . " righe.\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "- {$table}: ERRORE durante l'importazione: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Fatto ===\n";
echo "Controlla i numeri sopra: dovrebbero corrispondere al contenuto dei tuoi vecchi file CSV.\n";
echo "I file CSV originali in data/ NON sono stati toccati, restano come backup.\n";
echo "Ora ELIMINA questo file (migrate_to_db.php) dal server.\n";
