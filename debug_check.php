<?php
/**
 * Script diagnostico temporaneo: da caricare nella root del sito, visitare
 * una volta, copiare l'output e poi ELIMINARE dal server. Non dipende da
 * config.php per poter mostrare l'errore anche se l'app intera è rotta.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ob_start(); // evita output prematuro, altrimenti session_start() in config.php si lamenta
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . phpversion() . "\n";
echo "Data/ora server: " . date('Y-m-d H:i:s') . "\n\n";

echo "--- Prova caricamento lib/CsvStorage.php ---\n";
try {
    require __DIR__ . '/lib/CsvStorage.php';
    echo "OK: lib/CsvStorage.php caricato correttamente.\n";
} catch (\Throwable $e) {
    echo "ERRORE: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " linea " . $e->getLine() . "\n";
}

echo "\n--- Prova caricamento config.php ---\n";
try {
    require __DIR__ . '/config.php';
    echo "OK: config.php caricato correttamente.\n";
} catch (\Throwable $e) {
    echo "ERRORE: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " linea " . $e->getLine() . "\n";
}
