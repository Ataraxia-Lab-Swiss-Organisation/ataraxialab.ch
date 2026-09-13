<?php
/**
 * cron_pagespeed.php — Ataraxia Lab — ataraxialab.ch
 *
 * Interroge l'API Google PageSpeed Insights (mobile + desktop) pour la page
 * d'accueil et écrit le résultat en JSON, exposé publiquement pour lecture
 * par audits/agents IA. Voir D-1, sites/ataraxialab.md.
 *
 * Déclenchement : AtxCron par URL (fire_and_forget désactivé — script répond
 * 202 immédiatement via fastcgi_finish_request() puis traite en background).
 *   https://ataraxialab.ch/cron/cron_pagespeed.php?token=XXX
 *
 * Fréquence : hebdomadaire, lundi matin.
 * Idempotent : écrase intégralement le fichier de sortie à chaque exécution.
 *
 * PATCH-IK-BG-002 13.09.2026 : fastcgi_finish_request() — réponse immédiate,
 * traitement long en background (remplace ignore_user_abort seul — PATCH-IK-BG-001).
 * AtxCron : fire_and_forget=0, timeout=10 (suffisant pour recevoir le 202).
 *
 * Secrets : clé API PageSpeed + token cron lus depuis un fichier hors-repo
 * — JAMAIS en dur dans ce script (cf. RÈGLES ABSOLUES DE SÉCURITÉ,
 * instructions de projet §5). Convention : tokens générés via Bitwarden.
 */

declare(strict_types=1);

// IK mutualisé PHP-FPM : traitement long après fermeture connexion client
ignore_user_abort(true);
set_time_limit(300);

const TARGET_URL    = 'https://ataraxialab.ch/';
const OUTPUT_PATH   = __DIR__ . '/../cron-data/performance.json';
const API_ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const SECRET_PATH   = __DIR__ . '/../secrets/pagespeed_api_key.php';

function loadSecrets(): array
{
    if (!file_exists(SECRET_PATH)) {
        http_response_code(500);
        echo json_encode(['error' => 'secret_file_missing']);
        flushAndFinish();
        exit;
    }
    $secrets = require_once SECRET_PATH;
    if (!is_array($secrets) || empty($secrets['api_key']) || empty($secrets['cron_token'])) {
        http_response_code(500);
        echo json_encode(['error' => 'secret_file_invalid']);
        flushAndFinish();
        exit;
    }
    return $secrets;
}

function checkToken(string $expectedToken): void
{
    $provided = $_GET['token'] ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals($expectedToken, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        flushAndFinish();
        exit;
    }
}

/**
 * Ferme la connexion HTTP vers AtxCron et continue en background (PHP-FPM).
 * Doit être appelé APRÈS avoir envoyé tous les headers et le body.
 */
function flushAndFinish(): void
{
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/**
 * @param array<string, mixed> $categories
 * @return array{performance:int,accessibility:int,best_practices:int,seo:int}
 */
function extractScores(array $categories): array
{
    $scoreOf = static function (array $cats, string $key): int {
        return isset($cats[$key]['score'])
            ? (int) round($cats[$key]['score'] * 100)
            : 0;
    };
    return [
        'performance'    => $scoreOf($categories, 'performance'),
        'accessibility'  => $scoreOf($categories, 'accessibility'),
        'best_practices' => $scoreOf($categories, 'best-practices'),
        'seo'            => $scoreOf($categories, 'seo'),
    ];
}

function buildEndpoint(string $url, string $strategy, string $apiKey): string
{
    $base = http_build_query(['url' => $url, 'strategy' => $strategy, 'key' => $apiKey]);
    $cats = implode('&', array_map(
        static fn(string $c): string => 'category=' . urlencode($c),
        ['performance', 'accessibility', 'best-practices', 'seo']
    ));
    return API_ENDPOINT . '?' . $base . '&' . $cats;
}

/**
 * Parse la réponse cURL de l'API PageSpeed (max 3 return — S1142).
 * Les échecs cURL et HTTP sont regroupés dans une garde unique.
 *
 * @return array{performance:int,accessibility:int,best_practices:int,seo:int}|null
 */
function parsePageSpeedResponse(mixed $response, int $httpCode, string $curlError, string $strategy): ?array
{
    if ($response === false || $httpCode !== 200) {
        $detail = $response === false ? $curlError : substr((string)$response, 0, 300);
        error_log("[cron_pagespeed] Erreur ({$strategy}) HTTP {$httpCode} : {$detail}");
        return null;
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data) || !isset($data['lighthouseResult']['categories'])) {
        error_log("[cron_pagespeed] Réponse JSON inattendue ({$strategy})");
        return null;
    }
    return extractScores($data['lighthouseResult']['categories']);
}

/**
 * @return array{performance:int,accessibility:int,best_practices:int,seo:int}|null
 */
function fetchScores(string $url, string $strategy, string $apiKey): ?array
{
    $ch = curl_init(buildEndpoint($url, $strategy, $apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FAILONERROR    => false,
    ]);
    return parsePageSpeedResponse(
        curl_exec($ch),
        curl_getinfo($ch, CURLINFO_HTTP_CODE),
        curl_error($ch),
        $strategy
    );
}

// ── Entrée principale ─────────────────────────────────────────────────────────

$secrets = loadSecrets();
checkToken($secrets['cron_token']);

// Répondre 202 immédiatement — AtxCron considère la tâche comme lancée
http_response_code(202);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'accepted', 'message' => 'pagespeed background processing started']);
flushAndFinish();

// ── Traitement en background (connexion client fermée) ────────────────────────

$apiKey = $secrets['api_key'];

$mobileScores  = fetchScores(TARGET_URL, 'mobile', $apiKey);
$desktopScores = fetchScores(TARGET_URL, 'desktop', $apiKey);

if ($mobileScores === null || $desktopScores === null) {
    error_log('[cron_pagespeed] Échec API PageSpeed. Fichier non mis à jour.');
    exit;
}

$result = [
    'updated_at'  => gmdate('c'),
    'url'         => TARGET_URL,
    'scores'      => ['mobile' => $mobileScores, 'desktop' => $desktopScores],
    'source'      => 'Google PageSpeed Insights API',
    'methodology' => 'Lighthouse, audit hebdomadaire automatisé',
];

$json      = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$outputDir = dirname(OUTPUT_PATH);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$written = file_put_contents(OUTPUT_PATH, $json . "\n");
if ($written === false) {
    error_log('[cron_pagespeed] Erreur : impossible d\'écrire ' . OUTPUT_PATH);
}
