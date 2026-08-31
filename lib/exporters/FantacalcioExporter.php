<?php
/**
 * Esportazione delle rose dell'asta in formati adattabili a Leghe Fantacalcio.
 *
 * IMPORTANTE: il formato esatto richiesto da Leghe Fantacalcio (nomi colonna,
 * ordine, encoding, separatore, intestazioni) NON è stato fornito nel
 * repository. Questo exporter usa quindi una struttura generica e
 * ragionevole (compatibile con la maggior parte degli importatori di lega),
 * isolata qui in modo da poter essere adattata in un unico punto non
 * appena verrà fornito un file di esempio ufficiale.
 *
 * Il resto dell'applicazione non dipende in alcun modo da questo formato:
 * riceve solo array PHP (rows) da AuctionService/TeamService.
 */

declare(strict_types=1);

final class FantacalcioExporter
{
    /**
     * Intestazioni colonna dell'export. Modificare qui per adattarsi al
     * formato ufficiale Leghe Fantacalcio quando disponibile.
     */
    public const HEADERS = ['Fantasquadra', 'Ruolo', 'Nome', 'Squadra', 'Quotazione', 'FVM', 'Prezzo Acquisto'];

    /**
     * Costruisce le righe (array associativi) per l'intera asta o per una
     * singola squadra (se $teamId è indicato).
     */
    public static function buildRows(int $auctionId, ?int $teamId = null): array
    {
        $rows = [];
        $teams = $teamId !== null
            ? array_filter(TeamService::getAuctionTeams($auctionId), fn($t) => (int)$t['id'] === $teamId)
            : TeamService::getAuctionTeams($auctionId);

        foreach ($teams as $team) {
            $roster = AuctionService::getTeamRoster($auctionId, (int)$team['id']);
            foreach ($roster as $r) {
                $rows[] = [
                    'Fantasquadra' => $team['name'],
                    'Ruolo' => $r['role'],
                    'Nome' => $r['name'],
                    'Squadra' => $r['real_team'],
                    'Quotazione' => $r['quotation'],
                    'FVM' => $r['fvm'],
                    'Prezzo Acquisto' => $r['price'],
                ];
            }
        }
        return $rows;
    }

    /**
     * Genera il contenuto CSV (stringa) per l'export. Encoding UTF-8,
     * separatore ",". BOM incluso per compatibilità Excel.
     */
    public static function toCsvString(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($fh, self::HEADERS, ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach (self::HEADERS as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fh, $line, ',', '"', '\\');
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);
        return $content;
    }

    /**
     * Genera un file XLSX (richiede PhpSpreadsheet via composer). Restituisce
     * il percorso del file temporaneo creato, oppure null se PhpSpreadsheet
     * non è disponibile.
     */
    public static function toXlsxFile(array $rows, string $title = 'Export'): ?string
    {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return null;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31));

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach (self::HEADERS as $i => $col) {
                $sheet->setCellValueByColumnAndRow($i + 1, $rowIndex, $row[$col] ?? '');
            }
            $rowIndex++;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tmpFile);

        return $tmpFile;
    }
}
