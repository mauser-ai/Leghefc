<?php
/**
 * CsvStorage centralizza tutta la lettura/scrittura dei file CSV usati come
 * storage dell'applicazione. Nessuna altra parte del codice deve aprire un
 * file CSV direttamente: tutte le pagine e i servizi passano da qui.
 *
 * Garantisce:
 *  - lettura/scrittura con flock (LOCK_SH / LOCK_EX)
 *  - scritture atomiche (file temporaneo + rename)
 *  - transazioni read-modify-write con lock esclusivo continuo
 */

declare(strict_types=1);

class CsvStorage
{
    private const DELIMITER = ',';

    /**
     * Percorso assoluto di un file dati, dato il nome file (es. "users.csv").
     */
    public static function path(string $filename): string
    {
        return DATA_DIR . '/' . $filename;
    }

    /**
     * Crea il file con la sola riga di intestazione se non esiste ancora.
     */
    public static function ensure(string $filename, array $headers): void
    {
        $path = self::path($filename);
        if (!is_file($path)) {
            $fh = fopen($path, 'c');
            if ($fh === false) {
                throw new RuntimeException("Impossibile creare il file $filename");
            }
            flock($fh, LOCK_EX);
            fputcsv($fh, $headers, self::DELIMITER, '"', '\\');
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Legge tutte le righe come array associativi (chiave = intestazione colonna).
     * Se il file non esiste viene creato vuoto con gli headers indicati.
     */
    public static function readAll(string $filename, array $headers): array
    {
        self::ensure($filename, $headers);
        $path = self::path($filename);

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        $rows = [];
        if (flock($fh, LOCK_SH)) {
            $fileHeaders = fgetcsv($fh, 0, self::DELIMITER, '"', '\\');
            if ($fileHeaders !== false) {
                while (($data = fgetcsv($fh, 0, self::DELIMITER, '"', '\\')) !== false) {
                    if (count($data) === 1 && $data[0] === null) {
                        continue; // riga vuota
                    }
                    $row = [];
                    foreach ($fileHeaders as $i => $col) {
                        $row[$col] = $data[$i] ?? '';
                    }
                    $rows[] = $row;
                }
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return $rows;
    }

    /**
     * Scrive l'intero contenuto del file in modo atomico (tmp + rename),
     * mantenendo un lock esclusivo sul file di destinazione durante l'operazione.
     */
    public static function writeAll(string $filename, array $rows, array $headers): void
    {
        $path = self::path($filename);
        $dir = dirname($path);

        // Lock sul file reale per serializzare gli scrittori concorrenti.
        $lockHandle = fopen($path, 'c');
        if ($lockHandle === false) {
            throw new RuntimeException("Impossibile aprire il file $filename per la scrittura");
        }
        flock($lockHandle, LOCK_EX);

        $tmpPath = $path . '.tmp' . bin2hex(random_bytes(4));
        $tmp = fopen($tmpPath, 'w');
        if ($tmp === false) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw new RuntimeException("Impossibile creare file temporaneo per $filename");
        }

        fputcsv($tmp, $headers, self::DELIMITER, '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($tmp, $line, self::DELIMITER, '"', '\\');
        }
        fflush($tmp);
        fclose($tmp);

        rename($tmpPath, $path);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    /**
     * Transazione read-modify-write con lock esclusivo mantenuto per tutta
     * la durata dell'operazione (nessun'altra scrittura può intromettersi).
     *
     * $mutator riceve l'array di righe correnti e deve restituire il nuovo
     * array di righe da salvare, oppure null per abortire senza scrivere.
     */
    public static function transaction(string $filename, array $headers, callable $mutator): mixed
    {
        self::ensure($filename, $headers);
        $path = self::path($filename);

        $fh = fopen($path, 'r+');
        if ($fh === false) {
            throw new RuntimeException("Impossibile aprire il file $filename");
        }

        flock($fh, LOCK_EX);

        $fileHeaders = fgetcsv($fh, 0, self::DELIMITER, '"', '\\');
        $rows = [];
        if ($fileHeaders !== false) {
            while (($data = fgetcsv($fh, 0, self::DELIMITER, '"', '\\')) !== false) {
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

        $result = $mutator($rows, $headers);
        $newRows = is_array($result) ? $result : (is_array($result['rows'] ?? null) ? $result['rows'] : null);

        if ($newRows !== null) {
            rewind($fh);
            ftruncate($fh, 0);
            fputcsv($fh, $headers, self::DELIMITER, '"', '\\');
            foreach ($newRows as $row) {
                $line = [];
                foreach ($headers as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($fh, $line, self::DELIMITER, '"', '\\');
            }
            fflush($fh);
        }

        flock($fh, LOCK_UN);
        fclose($fh);

        return $result;
    }

    /**
     * Aggiunge una riga in fondo al file assegnando automaticamente un id
     * incrementale se la colonna "id" è tra gli headers e non è valorizzata.
     * Restituisce la riga effettivamente scritta (con id assegnato).
     */
    public static function append(string $filename, array $headers, array $row): array
    {
        $written = null;
        self::transaction($filename, $headers, function (array $rows) use (&$written, $row, $headers) {
            if (in_array('id', $headers, true) && empty($row['id'])) {
                $maxId = 0;
                foreach ($rows as $r) {
                    $maxId = max($maxId, (int)($r['id'] ?? 0));
                }
                $row['id'] = (string)($maxId + 1);
            }
            $written = $row;
            $rows[] = $row;
            return $rows;
        });
        return $written;
    }

    /**
     * Aggiorna le righe che soddisfano $predicate applicando $updater.
     * Restituisce il numero di righe modificate.
     */
    public static function update(string $filename, array $headers, callable $predicate, callable $updater): int
    {
        $count = 0;
        CsvStorage::transaction($filename, $headers, function (array $rows) use (&$count, $predicate, $updater) {
            foreach ($rows as $i => $row) {
                if ($predicate($row)) {
                    $rows[$i] = $updater($row);
                    $count++;
                }
            }
            return $rows;
        });
        return $count;
    }

    /**
     * Rimuove fisicamente le righe che soddisfano $predicate (hard delete).
     * Da usare solo quando il soft-delete (campo active/enabled) non è applicabile.
     */
    public static function delete(string $filename, array $headers, callable $predicate): int
    {
        $count = 0;
        CsvStorage::transaction($filename, $headers, function (array $rows) use (&$count, $predicate) {
            $kept = [];
            foreach ($rows as $row) {
                if ($predicate($row)) {
                    $count++;
                } else {
                    $kept[] = $row;
                }
            }
            return $kept;
        });
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
