<?php
/**
 * cron_pagespeed.php — Ataraxia Lab — ataraxialab.ch
 *
 * Interroge l'API Google PageSpeed Insights (mobile + desktop) pour la page
 * d'accueil et écrit le résultat en JSON, exposé publiquement pour lecture
 * par audits/agents IA. Voir D-1, sites/ataraxialab.md.
 *
 * Exécution : cron hebdomadaire (IK Manager > Crons), lundi matin.
 * Idempotent : écrase intégralement le fichier de sortie à chaque exécution.
 *
 * Secret : la clé API est lue depuis une variable d'environnement ou un
 * fichier hors-repo — JAMAIS en dur dans ce script (cf. RÈGLES ABSOLUES
 * DE SÉCURITÉ, instructions de projet §5).
 */

declare(strict_types=1);

// --- Configuration ---------------------------------------------------

const TARGET_URL    = 'https://ataraxialab.ch/';
const OUTPUT_PATH   = __DIR__ . '/../cron-data/performance.json';
const API_ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const SECRET_PATH   = __DIR__ . '/../secrets/pagespeed_api_key.php';
// Le fichier secret doit définir : <?php return 'AIzaSy...'; (hors repo, sur IK uniquement)

// --- Lecture sécurisée de la clé API ----------------------------------

function loadApiKey(): string
{
    if (!file_exists(SECRET_PATH)) {
        fwrite(STDERR, "[cron_pagespeed] Erreur : fichier secret introuvable (" . SECRET_PATH . ")\n");
        exit(1);
    }
    $key = require SECRET_PATH;
    if (!is_string($key) || $key === '') {
        fwrite(STDERR, "[cron_pagespeed] Erreur : clé API vide ou invalide\n");
        exit(1);
    }
    return $key;
}

// --- Appel API PageSpeed Insights -------------------------------------

/**
 * @return array{performance:int,accessibility:int,best_practices:int,seo:int}|null
 */
function fetchScores(string $url, string $strategy, string $apiKey): ?array
{
    $query = http_build_query([
        'url'      => $url,
        'strategy' => $strategy,
        'key'      => $apiKey,
        'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
    ]);

    $endpoint = API_ENDPOINT . '?' . $query;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FAILONERROR    => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        fwrite(STDERR, "[cron_pagespeed] Erreur cURL ({$strategy}) : {$curlError}\n");
        return null;
    }

    if ($httpCode !== 200) {
        fwrite(STDERR, "[cron_pagespeed] HTTP {$httpCode} ({$strategy}) : " . substr($response, 0, 300) . "\n");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['lighthouseResult']['categories'])) {
        fwrite(STDERR, "[cron_pagespeed] Réponse JSON inattendue ({$strategy})\n");
        return null;
    }

    $categories = $data['lighthouseResult']['categories'];

    $scoreOf = static function (array $categories, string $key): int {
        return isset($categories[$key]['score'])
            ? (int) round($categories[$key]['score'] * 100)
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

$apiKey = loadApiKey();

$mobileScores  = fetchScores(TARGET_URL, 'mobile', $apiKey);
$desktopScores = fetchScores(TARGET_URL, 'desktop', $apiKey);

if ($mobileScores === null || $desktopScores === null) {
    fwrite(STDERR, "[cron_pagespeed] Échec : au moins une stratégie n'a pas pu être auditée. Fichier non mis à jour.\n");
    exit(1);
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
    fwrite(STDERR, "[cron_pagespeed] Erreur : impossible d'écrire " . OUTPUT_PATH . "\n");
    exit(1);
}

echo "[cron_pagespeed] OK — " . OUTPUT_PATH . " mis à jour (" . date('Y-m-d H:i:s') . ")\n";
exit(0);
