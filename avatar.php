<?php
/**
 * Proxy/cache lato server per le card giocatore di fantacalcio.it.
 *
 * Il sito fantacalcio.it protegge le proprie immagini dall'hotlinking
 * (richieste dirette dal browser dell'utente da un dominio esterno falliscono),
 * quindi la card viene scaricata qui **dal server** — che può presentarsi con
 * un Referer plausibile — salvata in cache locale (/data/avatar_cache) e poi
 * servita al browser dal nostro stesso dominio. Se il download fallisce (id
 * inesistente, servizio irraggiungibile, ecc.) risponde 404: il frontend ha
 * già un fallback grafico (pallino colorato con la lettera del ruolo) per
 * questo caso, quindi qui non serve altro che restituire l'errore.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$externalId = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
if ($externalId === '') {
    http_response_code(404);
    exit;
}

$cachePath = AVATAR_CACHE_DIR . '/' . $externalId . '.png';
$cacheTtl = 30 * 86400; // 30 giorni: le foto cambiano raramente durante una stagione

function serveCachedImage(string $path): never
{
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

if (is_file($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    serveCachedImage($cachePath);
}

/**
 * Scarica l'immagine lato server, presentandosi con un Referer plausibile
 * per aggirare l'hotlink-protection (che blocca solo le richieste dirette
 * dal browser di terzi, non quelle originate dal nostro server).
 */
function fetchRemoteImage(string $url): ?string
{
    $headers = [
        'Referer: https://www.fantacalcio.it/',
        'User-Agent: Mozilla/5.0 (compatible; FantacalcioAstaManager/1.0)',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($data !== false && $httpCode === 200 && str_starts_with($contentType, 'image/') && strlen($data) > 0) {
            return $data;
        }
        return null;
    }

    if (function_exists('ini_get') && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data !== false && strlen($data) > 0) {
            return $data;
        }
        return null;
    }

    return null;
}

$remoteUrl = "https://content.fantacalcio.it/web/campioncini/21/card/{$externalId}.png";
$imageData = fetchRemoteImage($remoteUrl);

if ($imageData !== null) {
    file_put_contents($cachePath, $imageData);
    serveCachedImage($cachePath);
}

// Download fallito: meglio servire una copia vecchia in cache che niente.
if (is_file($cachePath)) {
    serveCachedImage($cachePath);
}

http_response_code(404);
