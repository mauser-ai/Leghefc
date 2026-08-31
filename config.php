<?php
/**
 * Configurazione globale dell'applicazione Fantacalcio Asta Manager.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('BACKUP_DIR', DATA_DIR . '/backups');
define('LOCK_DIR', DATA_DIR . '/locks');

// Numero massimo di snapshot di backup da conservare.
define('BACKUP_MAX_SNAPSHOTS', 20);

foreach ([DATA_DIR, BACKUP_DIR, LOCK_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// Autoload semplice per le classi in /lib e /lib/exporters
spl_autoload_register(function (string $class): void {
    $candidates = [
        APP_ROOT . '/lib/' . $class . '.php',
        APP_ROOT . '/lib/exporters/' . $class . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Auth.php definisce sia la classe Auth sia gli helper globali requireLogin()/requireAdmin():
// va caricato esplicitamente perché l'autoload si attiva solo sull'uso della classe, non delle funzioni.
require_once APP_ROOT . '/lib/Auth.php';

// Sessioni PHP
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Europe/Rome');

function now(): string
{
    return date('Y-m-d H:i:s');
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function baseUrl(): string
{
    return '';
}
