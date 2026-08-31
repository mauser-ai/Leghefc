<?php
/**
 * Import del "listone" giocatori da CSV/XLSX con mappatura colonne
 * configurabile (non legata a un ordine fisso).
 */

declare(strict_types=1);

final class ImportService
{
    /** Colonne normalizzate richieste dal sistema. */
    public const TARGET_FIELDS = ['name', 'real_team', 'role', 'quotation', 'fvm', 'external_id'];

    /**
     * Suggerimenti automatici nome colonna sorgente -> campo normalizzato.
     *
     * Tarati sul template "Quotazioni Fantacalcio" (fantacalcio.it), che è
     * quello che verrà ricaricato periodicamente fino al giorno dell'asta:
     * colonne Id, R, RM, Nome, Squadra, Qt.A, Qt.I, Diff., Qt.A M, Qt.I M,
     * Diff.M, FVM, FVM M. Usiamo solo le colonne "classiche" (R, Qt.A, FVM):
     * "RM" è il ruolo Mantra (es. "Pc", "Ds;Dd") e le colonne "... M" sono i
     * valori Mantra — mapparle per errore su ruolo/quotazione produrrebbe
     * ruoli e prezzi sbagliati, quindi restano volutamente NON mappate di
     * default (l'admin può comunque sceglierle a mano nello step di mapping).
     */
    private const AUTO_MAP = [
        'nome' => 'name',
        'name' => 'name',
        'calciatore' => 'name',
        'giocatore' => 'name',
        'squadra' => 'real_team',
        'sq.' => 'real_team',
        'team' => 'real_team',
        'real_team' => 'real_team',
        'r' => 'role',
        'ruolo' => 'role',
        'role' => 'role',
        'qt.a' => 'quotation',
        'qta' => 'quotation',
        'quotazione' => 'quotation',
        'quotation' => 'quotation',
        'fvm' => 'fvm',
        'id' => 'external_id', // id ufficiale fantacalcio.it, usato per l'avatar del giocatore
    ];

    /** Nomi foglio (case-insensitive) da preferire nei file XLSX multi-foglio. */
    private const PREFERRED_SHEET_NAMES = ['tutti'];

    /** Nomi foglio da escludere sempre (es. giocatori ormai fuori rosa Serie A). */
    private const EXCLUDED_SHEET_NAMES = ['ceduti'];

    /**
     * Legge un file caricato (CSV o XLSX) e restituisce ['headers'=>[], 'rows'=>[][], 'suggested_map'=>[]].
     * Solleva RuntimeException in caso di formato non supportato o errore di lettura.
     */
    public static function readUploadedFile(string $tmpPath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            [$headers, $rows] = self::readCsv($tmpPath);
        } elseif ($ext === 'xlsx') {
            [$headers, $rows] = self::readXlsx($tmpPath);
        } else {
            throw new RuntimeException('Formato file non supportato. Usa CSV o XLSX.');
        }

        $suggestedMap = [];
        foreach ($headers as $h) {
            $key = mb_strtolower(trim($h));
            $suggestedMap[$h] = self::AUTO_MAP[$key] ?? '';
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'suggested_map' => $suggestedMap,
        ];
    }

    private static function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException('Impossibile leggere il file caricato.');
        }

        // Rileva automaticamente il delimitatore (',' o ';').
        $firstLine = fgets($fh);
        rewind($fh);
        $delimiter = (substr_count($firstLine ?: '', ';') > substr_count($firstLine ?: '', ',')) ? ';' : ',';

        $raw = [];
        while (($data = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            $raw[] = $data;
        }
        fclose($fh);

        return self::extractHeaderAndRows($raw);
    }

    private static function readXlsx(string $path): array
    {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'Import XLSX non disponibile: PhpSpreadsheet non è installato. ' .
                'Esegui "composer require phpoffice/phpspreadsheet" oppure carica il listone in formato CSV.'
            );
        }
        require_once $autoload;

        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('PhpSpreadsheet non disponibile.');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = self::pickSheet($spreadsheet);
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            throw new RuntimeException('File XLSX vuoto.');
        }

        return self::extractHeaderAndRows($data);
    }

    /**
     * Sceglie il foglio da importare in un file XLSX multi-foglio: preferisce
     * un foglio con nome tra PREFERRED_SHEET_NAMES (es. "Tutti" nel template
     * Quotazioni Fantacalcio), altrimenti usa il foglio attivo.
     */
    private static function pickSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (in_array(mb_strtolower(trim($sheet->getTitle())), self::PREFERRED_SHEET_NAMES, true)) {
                return $sheet;
            }
        }
        return $spreadsheet->getActiveSheet();
    }

    /**
     * Individua la vera riga di intestazione tra le righe grezze lette dal
     * file, saltando eventuali righe titolo (una sola cella valorizzata) o
     * righe vuote iniziali, poi costruisce gli array associativi delle righe.
     */
    private static function extractHeaderAndRows(array $rawRows): array
    {
        $headerIndex = null;
        foreach ($rawRows as $i => $line) {
            $nonEmpty = array_filter($line, fn($v) => $v !== null && trim((string)$v) !== '');
            if (count($nonEmpty) >= 2) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException('Impossibile individuare la riga di intestazione nel file.');
        }

        $headers = array_map(fn($h) => trim((string)$h), $rawRows[$headerIndex]);

        $rows = [];
        foreach (array_slice($rawRows, $headerIndex + 1) as $line) {
            $nonEmpty = array_filter($line, fn($v) => $v !== null && trim((string)$v) !== '');
            if (empty($nonEmpty)) {
                continue; // riga vuota
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    /**
     * Normalizza il valore ruolo in un codice singolo (P/D/C/A).
     */
    public static function normalizeRole(string $raw): string
    {
        $raw = mb_strtoupper(trim($raw));
        $raw = explode(';', $raw)[0]; // gestisce ruoli multipli tipo "C;A" -> prende il primo
        return match (true) {
            str_starts_with($raw, 'P') => Schema::ROLE_GK,
            str_starts_with($raw, 'D') => Schema::ROLE_DEF,
            str_starts_with($raw, 'C') || str_starts_with($raw, 'M') => Schema::ROLE_MID,
            str_starts_with($raw, 'A') || str_starts_with($raw, 'F') => Schema::ROLE_ATT,
            default => Schema::ROLE_MID,
        };
    }

    /**
     * Applica la mappatura colonne scelta dall'utente e produce l'elenco
     * giocatori normalizzato pronto per PlayerService::replaceAll().
     *
     * $columnMap: [nome_colonna_sorgente => campo_normalizzato]
     */
    public static function applyMapping(array $rows, array $columnMap): array
    {
        // Inverte per campo normalizzato -> colonna sorgente
        $fieldToColumn = array_flip(array_filter($columnMap, fn($v) => $v !== ''));

        $players = [];
        foreach ($rows as $row) {
            $name = trim((string)($row[$fieldToColumn['name'] ?? ''] ?? ''));
            if ($name === '') {
                continue;
            }
            $players[] = [
                'name' => $name,
                'real_team' => trim((string)($row[$fieldToColumn['real_team'] ?? ''] ?? '')),
                'role' => self::normalizeRole((string)($row[$fieldToColumn['role'] ?? ''] ?? '')),
                'quotation' => (string)(int)preg_replace('/[^0-9\-]/', '', (string)($row[$fieldToColumn['quotation'] ?? ''] ?? '0') ?: '0'),
                'fvm' => (string)(int)preg_replace('/[^0-9\-]/', '', (string)($row[$fieldToColumn['fvm'] ?? ''] ?? '0') ?: '0'),
                'external_id' => preg_replace('/[^0-9]/', '', (string)($row[$fieldToColumn['external_id'] ?? ''] ?? '')),
            ];
        }

        return $players;
    }

    /**
     * Importa i giocatori normalizzati sostituendo il listone globale e
     * aggiorna la disponibilità per tutte le aste esistenti.
     */
    public static function importPlayers(array $players): int
    {
        // L'import riassegna da zero gli id interni dei giocatori: se esistono già
        // acquisti attivi, un reimport li lascerebbe puntare a giocatori sbagliati.
        // Prima dell'asta (nessun acquisto registrato) i reimport ripetuti sono invece sicuri.
        if (AuctionService::hasAnyActivePurchase()) {
            throw new RuntimeException(
                'Impossibile reimportare il listone: esistono già acquisti registrati in almeno un\'asta. ' .
                'Un nuovo import riassegnerebbe gli identificativi dei giocatori e romperebbe le rose già acquistate. ' .
                'Completa/archivia le aste con acquisti prima di reimportare, oppure crea una nuova asta.'
            );
        }

        BackupService::snapshot('before_import_listone');
        PlayerService::replaceAll($players);

        foreach (AuctionService::listAll() as $auction) {
            PlayerService::ensureAuctionPlayers((int)$auction['id']);
        }

        AuditService::log('IMPORT_PLAYERS', null, null, null, null, null, (string)count($players) . ' giocatori');

        return count($players);
    }
}
