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
define('AVATAR_CACHE_DIR', DATA_DIR . '/avatar_cache');

/**
 * Percorso base dell'app rispetto alla root del dominio, dedotto automaticamente
 * confrontando la cartella di questo file con la document root di Apache. Permette
 * di installare l'app sia in radice (https://esempio.it/) sia in una sottocartella
 * (https://esempio.it/fanta/) senza alcuna configurazione manuale: tutti i link,
 * redirect e chiamate AJAX generati da url()/redirect() lo tengono automaticamente
 * in conto.
 */
$computedBasePath = '';
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $documentRoot = rtrim((string)(realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/\\');
    $appRoot = rtrim((string)(realpath(APP_ROOT) ?: APP_ROOT), '/\\');
    if ($documentRoot !== '' && str_starts_with($appRoot, $documentRoot)) {
        $computedBasePath = str_replace('\\', '/', substr($appRoot, strlen($documentRoot)));
    }
}
define('BASE_PATH', $computedBasePath); // es. '' in radice, '/fanta' in sottocartella
unset($computedBasePath, $documentRoot, $appRoot);

// Numero massimo di snapshot di backup da conservare.
define('BACKUP_MAX_SNAPSHOTS', 20);

foreach ([DATA_DIR, BACKUP_DIR, LOCK_DIR, AVATAR_CACHE_DIR] as $dir) {
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

// Sessioni PHP (cookie limitato al percorso base dell'app, non a tutto il dominio)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_PATH !== '' ? BASE_PATH . '/' : '/',
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

/**
 * Antepone il percorso base dell'app (BASE_PATH) a un path assoluto interno
 * (che inizia con '/'), così i link funzionano sia in radice che in una
 * sottocartella. Un path relativo (senza '/' iniziale) o già esterno
 * (http.../https...) viene restituito invariato.
 */
function url(string $path): string
{
    if ($path === '' || !str_starts_with($path, '/')) {
        return $path;
    }
    return BASE_PATH . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function baseUrl(): string
{
    return BASE_PATH;
}
