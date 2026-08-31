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
 *
 * Modalità diagnostica (solo admin): /avatar.php?id=XXXX&debug=1 restituisce
 * un JSON con il motivo esatto di un eventuale fallimento, invece
 * dell'immagine, per capire rapidamente cosa non va sull'hosting in uso.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireLogin();

$externalId = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
$debug = ($_GET['debug'] ?? '') === '1' && Auth::isAdmin();

if ($externalId === '') {
    if ($debug) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Parametro id mancante o non numerico.']);
        exit;
    }
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

if (!$debug && is_file($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    serveCachedImage($cachePath);
}

/**
 * Scarica l'immagine lato server, presentandosi con un Referer plausibile
 * per aggirare l'hotlink-protection (che blocca solo le richieste dirette
 * dal browser di terzi, non quelle originate dal nostro server).
 * Popola $diag con i dettagli del tentativo, usati dalla modalità debug.
 */
function fetchRemoteImage(string $url, array &$diag): ?string
{
    $headers = [
        'Referer: https://www.fantacalcio.it/',
        'User-Agent: Mozilla/5.0 (compatible; FantacalcioAstaManager/1.0)',
    ];

    $diag['curl_available'] = function_exists('curl_init');
    $diag['allow_url_fopen'] = (bool)(function_exists('ini_get') && ini_get('allow_url_fopen'));

    if ($diag['curl_available']) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $diag['method'] = 'curl';
        $diag['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $diag['content_type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $diag['bytes_received'] = $data === false ? 0 : strlen($data);
        $diag['curl_errno'] = curl_errno($ch);
        $diag['curl_error'] = curl_error($ch);
        curl_close($ch);

        if ($data !== false && $diag['http_code'] === 200 && str_starts_with((string)$diag['content_type'], 'image/') && strlen($data) > 0) {
            return $data;
        }
        return null;
    }

    if ($diag['allow_url_fopen']) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        $diag['method'] = 'file_get_contents';
        $diag['response_headers'] = $http_response_header ?? [];
        $diag['bytes_received'] = $data === false ? 0 : strlen($data);
        $diag['last_error'] = error_get_last()['message'] ?? null;
        if ($data !== false && strlen($data) > 0) {
            return $data;
        }
        return null;
    }

    $diag['method'] = 'none';
    $diag['reason'] = 'Né l\'estensione curl né allow_url_fopen sono disponibili su questo hosting: impossibile scaricare immagini esterne lato server.';
    return null;
}

$remoteUrl = "https://content.fantacalcio.it/web/campioncini/21/card/{$externalId}.png";
$diag = ['remote_url' => $remoteUrl, 'cache_path_exists_before' => is_file($cachePath)];
$imageData = fetchRemoteImage($remoteUrl, $diag);

if ($imageData !== null) {
    file_put_contents($cachePath, $imageData);
    if ($debug) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'diag' => $diag], JSON_PRETTY_PRINT);
        exit;
    }
    serveCachedImage($cachePath);
}

if ($debug) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'diag' => $diag], JSON_PRETTY_PRINT);
    exit;
}

// Download fallito: meglio servire una copia vecchia in cache che niente.
if (is_file($cachePath)) {
    serveCachedImage($cachePath);
}

http_response_code(404);
