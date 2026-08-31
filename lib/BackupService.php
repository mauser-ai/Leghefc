<?php
/**
 * Gestione degli snapshot di backup dei file CSV.
 * Uno snapshot viene creato solo in occasione di operazioni che modificano
 * i dati (mai ad ogni polling AJAX), e ne vengono conservati al massimo
 * BACKUP_MAX_SNAPSHOTS.
 */

declare(strict_types=1);

final class BackupService
{
    private const FILES_TO_BACKUP = [
        Schema::USERS,
        Schema::TEAMS,
        Schema::AUCTIONS,
        Schema::AUCTION_TEAMS,
        Schema::PLAYERS,
        Schema::AUCTION_PLAYERS,
        Schema::PURCHASES,
        Schema::CURRENT_AUCTION,
    ];

    public static function snapshot(string $reason = ''): void
    {
        $stamp = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dir = BACKUP_DIR . '/' . $stamp;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (self::FILES_TO_BACKUP as $file) {
            $src = DATA_DIR . '/' . $file;
            if (is_file($src)) {
                copy($src, $dir . '/' . $file);
            }
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
