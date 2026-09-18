<?php
/**
 * Gestione degli snapshot di backup dei dati.
 * Uno snapshot viene creato solo in occasione di operazioni che modificano
 * i dati (mai ad ogni polling AJAX), e ne vengono conservati al massimo
 * BACKUP_MAX_SNAPSHOTS. Dalla migrazione a MySQL, ogni snapshot esporta lo
 * stato corrente delle tabelle in file CSV leggibili (stessa struttura di
 * quando lo storage erano i CSV stessi), utili sia come backup consultabile
 * a occhio sia come base per un eventuale ripristino manuale.
 */

declare(strict_types=1);

final class BackupService
{
    private const TABLES_TO_BACKUP = [
        Schema::USERS => Schema::USERS_HEADERS,
        Schema::TEAMS => Schema::TEAMS_HEADERS,
        Schema::AUCTIONS => Schema::AUCTIONS_HEADERS,
        Schema::AUCTION_TEAMS => Schema::AUCTION_TEAMS_HEADERS,
        Schema::PLAYERS => Schema::PLAYERS_HEADERS,
        Schema::AUCTION_PLAYERS => Schema::AUCTION_PLAYERS_HEADERS,
        Schema::PURCHASES => Schema::PURCHASES_HEADERS,
        Schema::CURRENT_AUCTION => Schema::CURRENT_AUCTION_HEADERS,
    ];

    public static function snapshot(string $reason = ''): void
    {
        $stamp = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dir = BACKUP_DIR . '/' . $stamp;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (self::TABLES_TO_BACKUP as $file => $headers) {
            $rows = CsvStorage::readAll($file, $headers);
            $fh = fopen($dir . '/' . $file, 'w');
            if ($fh === false) {
                continue;
            }
            fputcsv($fh, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($fh, $line, ',', '"', '\\');
            }
            fclose($fh);
        }

        if ($reason !== '') {
            file_put_contents($dir . '/reason.txt', $reason);
        }

        self::pruneOldSnapshots();
    }

    private static function pruneOldSnapshots(): void
    {
        $entries = glob(BACKUP_DIR . '/*', GLOB_ONLYDIR);
        if ($entries === false) {
            return;
        }
        sort($entries);
        $excess = count($entries) - BACKUP_MAX_SNAPSHOTS;
        for ($i = 0; $i < $excess; $i++) {
            self::removeDir($entries[$i]);
        }
    }

    private static function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public static function listSnapshots(): array
    {
        $entries = glob(BACKUP_DIR . '/*', GLOB_ONLYDIR);
        if ($entries === false) {
            return [];
        }
        rsort($entries);
        return array_map('basename', $entries);
    }
}
