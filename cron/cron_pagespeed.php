<?php
/**
 * cron_pagespeed.php — Ataraxia Lab — ataraxialab.ch
 *
 * Interroge l'API Google PageSpeed Insights (mobile + desktop) pour la page
 * d'accueil et écrit le résultat en JSON, exposé publiquement pour lecture
 * par audits/agents IA. Voir D-1, sites/ataraxialab.md.
 *
 * Déclenchement : cron IK Manager par URL (pattern identique aux autres
 * crons de l'association — token en query string).
 *   https://ataraxialab.ch/cron/cron_pagespeed.php?token=XXX
 *
 * Fréquence : hebdomadaire, lundi matin.
 * Idempotent : écrase intégralement le fichier de sortie à chaque exécution.
 *
 * Secrets : clé API PageSpeed + token cron lus depuis un fichier hors-repo
 * — JAMAIS en dur dans ce script (cf. RÈGLES ABSOLUES DE SÉCURITÉ,
 * instructions de projet §5). Convention : tokens générés via Bitwarden.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Configuration ---------------------------------------------------

const TARGET_URL    = 'https://ataraxialab.ch/';
const OUTPUT_PATH   = __DIR__ . '/../cron-data/performance.json';
const API_ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const SECRET_PATH   = __DIR__ . '/../secrets/pagespeed_api_key.php';
// Le fichier secret doit retourner un tableau :
// <?php return ['api_key' => 'AIzaSy...', 'cron_token' => '...']; (hors repo, sur IK uniquement)

// --- Lecture sécurisée des secrets ------------------------------------

function loadSecrets(): array
{
    if (!file_exists(SECRET_PATH)) {
        http_response_code(500);
        echo json_encode(['error' => 'secret_file_missing']);
        exit;
    }
    $secrets = require_once SECRET_PATH;
    if (!is_array($secrets) || empty($secrets['api_key']) || empty($secrets['cron_token'])) {
        http_response_code(500);
        echo json_encode(['error' => 'secret_file_invalid']);
        exit;
    }
    return $secrets;
}

// --- Vérification du token (protection contre déclenchement non autorisé) --

function checkToken(string $expectedToken): void
{
    $provided = $_GET['token'] ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals($expectedToken, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

// --- Appel API PageSpeed Insights -------------------------------------

/**
 * @return array{performance:int,accessibility:int,best_practices:int,seo:int}|null
 */
function fetchScores(string $url, string $strategy, string $apiKey): ?array
{
    // http_build_query() encode un tableau en category[0]=...&category[1]=...,
    // format non reconnu par l'API Google qui attend category=x&category=y répété.
    // On construit donc la query manuellement pour ce paramètre.
    $baseParams = http_build_query([
        'url'      => $url,
        'strategy' => $strategy,
        'key'      => $apiKey,
    ]);

    $categoryParams = implode('&', array_map(
        static fn(string $cat): string => 'category=' . urlencode($cat),
        ['performance', 'accessibility', 'best-practices', 'seo']
    ));

    $endpoint = API_ENDPOINT . '?' . $baseParams . '&' . $categoryParams;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FAILONERROR    => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($response === false) {
        error_log("[cron_pagespeed] Erreur cURL ({$strategy}) : {$curlError}");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("[cron_pagespeed] HTTP {$httpCode} ({$strategy}) : " . substr((string)$response, 0, 300));
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
 * Extrait les scores des catégories Lighthouse.
 * Fonction séparée pour respecter la limite de 3 return par fonction (S1142).
 *
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

// --- Exécution ----------------------------------------------------------

$secrets = loadSecrets();
checkToken($secrets['cron_token']);

$apiKey = $secrets['api_key'];

$mobileScores  = fetchScores(TARGET_URL, 'mobile', $apiKey);
$desktopScores = fetchScores(TARGET_URL, 'desktop', $apiKey);

if ($mobileScores === null || $desktopScores === null) {
    error_log('[cron_pagespeed] Échec : au moins une stratégie n\'a pas pu être auditée. Fichier non mis à jour.');
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'pagespeed_api_failure']);
    exit;
}

$result = [
    'updated_at'  => gmdate('c'),
    'url'         => TARGET_URL,
    'scores'      => [
        'mobile'  => $mobileScores,
        'desktop' => $desktopScores,
    ],
    'source'      => 'Google PageSpeed Insights API',
    'methodology' => 'Lighthouse, audit hebdomadaire automatisé',
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$outputDir = dirname(OUTPUT_PATH);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$written = file_put_contents(OUTPUT_PATH, $json . "\n");

if ($written === false) {
    error_log('[cron_pagespeed] Erreur : impossible d\'écrire ' . OUTPUT_PATH);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'write_failure']);
    exit;
}

echo json_encode(['status' => 'ok', 'written_at' => OUTPUT_PATH, 'timestamp' => $result['updated_at']]);
