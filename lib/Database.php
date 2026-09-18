<?php
/**
 * Connessione PDO condivisa al database MySQL (una sola per richiesta).
 */

declare(strict_types=1);

final class Database
{
    private static $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Tutti i valori tornano come stringa, esattamente come le vecchie
                // righe lette dai CSV: il resto del codice fa già i cast (int)/(string)
                // che servono, senza bisogno di toccarlo.
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        }
        return self::$connection;
    }
}
