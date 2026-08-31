<?php
/**
 * Scrittura dello storico azioni (audit trail) su /data/audit.csv.
 */

declare(strict_types=1);

final class AuditService
{
    public static function log(
        string $action,
        ?int $auctionId = null,
        ?int $playerId = null,
        ?int $teamId = null,
        ?string $price = null,
        ?string $previousValue = null,
        ?string $newValue = null
    ): void {
        CsvStorage::append(Schema::AUDIT, Schema::AUDIT_HEADERS, [
            'timestamp' => now(),
            'user_id' => (string)(Auth::userId() ?? 0),
            'action' => $action,
            'auction_id' => $auctionId !== null ? (string)$auctionId : '',
            'player_id' => $playerId !== null ? (string)$playerId : '',
            'team_id' => $teamId !== null ? (string)$teamId : '',
            'price' => $price ?? '',
            'previous_value' => $previousValue ?? '',
            'new_value' => $newValue ?? '',
        ]);
    }

    public static function recent(int $limit = 100): array
    {
        $rows = CsvStorage::readAll(Schema::AUDIT, Schema::AUDIT_HEADERS);
        return array_slice(array_reverse($rows), 0, $limit);
    }

    public static function forAuction(int $auctionId, int $limit = 200): array
    {
        $rows = array_values(array_filter(
            CsvStorage::readAll(Schema::AUDIT, Schema::AUDIT_HEADERS),
            fn($r) => (int)($r['auction_id'] ?: 0) === $auctionId
        ));
        return array_slice(array_reverse($rows), 0, $limit);
    }
}
