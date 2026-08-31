<?php
/**
 * Import del "listone" giocatori da CSV/XLSX con mappatura colonne
 * configurabile (non legata a un ordine fisso).
 */

declare(strict_types=1);

final class ImportService
{
    /** Colonne normalizzate richieste dal sistema. */
    public const TARGET_FIELDS = ['name', 'real_team', 'role', 'quotation', 'fvm'];

    /** Suggerimenti automatici nome colonna sorgente -> campo normalizzato. */
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
        'rm' => 'role',
        'qt.a' => 'quotation',
        'qta' => 'quotation',
        'quotazione' => 'quotation',
        'quotation' => 'quotation',
        'qt.i' => 'quotation',
        'fvm' => 'fvm',
        'fvm m' => 'fvm',
    ];

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

        $headers = fgetcsv($fh, 0, $delimiter, '"', '\\');
        if ($headers === false) {
            fclose($fh);
            throw new RuntimeException('File CSV vuoto o non leggibile.');
        }
        $headers = array_map(fn($h) => trim((string)$h), $headers);

        $rows = [];
        while (($data = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return [$headers, $rows];
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
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            throw new RuntimeException('File XLSX vuoto.');
        }

        $headers = array_map(fn($h) => trim((string)$h), array_shift($data));
        $rows = [];
        foreach ($data as $line) {
            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
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
        BackupService::snapshot('before_import_listone');
        PlayerService::replaceAll($players);

        foreach (AuctionService::listAll() as $auction) {
            PlayerService::ensureAuctionPlayers((int)$auction['id']);
        }

        AuditService::log('IMPORT_PLAYERS', null, null, null, null, null, (string)count($players) . ' giocatori');

        return count($players);
    }
}
