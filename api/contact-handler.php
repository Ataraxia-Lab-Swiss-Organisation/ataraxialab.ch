<?php
/**
 * contact-handler.php — Ataraxia Lab — ataraxialab.ch
 *
 * Remplace Formspree (sous-traitant US — cf. D-2, sites/ataraxialab.md) par
 * un envoi SMTP natif via mail.infomaniak.com : zéro sous-traitant hors
 * Suisse pour les formulaires contact + devis de /contact/.
 *
 * Protections : honeypot (champ piège invisible), validation stricte des
 * entrées, anti-injection d'en-têtes (SmtpMailer::assertSafeHeaderValue),
 * throttle fichier par IP hashée (anti-flood, complémentaire au quota SMTP
 * IK — 500 envois/24h en kSuite Essential, technique/infomaniak_infra.md §17).
 *
 * Secrets : identifiants SMTP lus depuis un fichier hors-repo — JAMAIS en
 * dur (RÈGLES ABSOLUES DE SÉCURITÉ, instructions de projet §4).
 */

declare(strict_types=1);

require __DIR__ . '/lib/SmtpMailer.php';

use Ataraxia\Mail\SmtpMailer;

const SECRET_PATH  = __DIR__ . '/../secrets/smtp_credentials.php';
const THROTTLE_DIR = __DIR__ . '/../cron-data/contact-throttle';
const THROTTLE_MAX = 15; // soumissions / IP / jour — filet anti-flood, pas la défense principale
const RECIPIENT    = 'contact@ataraxialab.ch';
const SMTP_HOST    = 'mail.infomaniak.com';
const SMTP_PORT    = 587;

const REDIRECT_OK  = '/contact/?envoye=1#merci';
const REDIRECT_ERR = '/contact/?erreur=1#erreur';

function redirectTo(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function loadSecrets(): array
{
    if (!file_exists(SECRET_PATH)) {
        error_log('[contact-handler] secret_file_missing');
        redirectTo(REDIRECT_ERR);
    }
    $secrets = require SECRET_PATH;
    if (!is_array($secrets) || empty($secrets['smtp_user']) || empty($secrets['smtp_pass'])) {
        error_log('[contact-handler] secret_file_invalid');
        redirectTo(REDIRECT_ERR);
    }
    return $secrets;
}

function clientIpHash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip); // IP jamais stockée en clair, seulement son hash — nLPD
}

function checkThrottle(string $ipHash): void
{
    if (!is_dir(THROTTLE_DIR)) {
        mkdir(THROTTLE_DIR, 0755, true);
    }
    $file  = THROTTLE_DIR . '/' . date('Y-m-d') . '_' . substr($ipHash, 0, 16) . '.count';
    $count = file_exists($file) ? (int) file_get_contents($file) : 0;
    if ($count >= THROTTLE_MAX) {
        error_log('[contact-handler] throttle_depasse');
        redirectTo(REDIRECT_ERR);
    }
    file_put_contents($file, (string) ($count + 1));
}

function cleanupOldThrottleFiles(): void
{
    // Nettoyage paresseux : purge les fichiers de plus de 2 jours à chaque appel
    if (!is_dir(THROTTLE_DIR)) {
        return;
    }
    foreach (glob(THROTTLE_DIR . '/*.count') ?: [] as $file) {
        if (filemtime($file) < strtotime('-2 days')) {
            @unlink($file);
        }
    }
}

function field(string $name, int $maxLength = 5000): string
{
    $value = trim((string) ($_POST[$name] ?? ''));
    return mb_substr($value, 0, $maxLength);
}

// --- Entrée -------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectTo('/contact/');
}

// Honeypot — champ "site-web" masqué en CSS, un humain ne le remplit jamais
if (field('site-web') !== '') {
    // Bot détecté : réponse identique au succès, sans envoi d'email
    redirectTo(REDIRECT_OK);
}

$ipHash = clientIpHash();
cleanupOldThrottleFiles();
checkThrottle($ipHash);

$secrets = loadSecrets();
$mailer  = new SmtpMailer(SMTP_HOST, SMTP_PORT, $secrets['smtp_user'], $secrets['smtp_pass']);

$type = field('form-type', 20); // "contact" ou "devis"

if ($type === 'devis') {
    $nom        = field('devis-nom', 200);
    $email      = field('devis-email', 200);
    $prestation = field('devis-type', 100);
    $projet     = field('devis-projet', 5000);

    if ($nom === '' || $email === '' || $projet === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectTo(REDIRECT_ERR);
    }

    $subject = "Demande de devis — {$prestation}";
    $body = "Nouvelle demande de devis via ataraxialab.ch\n\n"
          . "Nom / Organisation : {$nom}\n"
          . "Email : {$email}\n"
          . "Prestation : {$prestation}\n\n"
          . "Description du projet :\n{$projet}\n";
} else {
    $nom     = field('nom', 200);
    $email   = field('email', 200);
    $message = field('message', 5000);

    if ($nom === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectTo(REDIRECT_ERR);
    }

    $subject = 'Nouveau message — formulaire de contact';
    $body = "Nouveau message via ataraxialab.ch\n\n"
          . "Nom : {$nom}\n"
          . "Email : {$email}\n\n"
          . "Message :\n{$message}\n";
}

try {
    $mailer->send(
        RECIPIENT,                     // enveloppe From = boîte propre (alignement SPF/DKIM/DMARC)
        'Formulaire ataraxialab.ch',
        RECIPIENT,
        $email,                         // Reply-To = expéditeur, pour répondre directement
        $subject,
        $body
    );
} catch (\Throwable $e) {
    error_log('[contact-handler] envoi_echoue: ' . $e->getMessage());
    redirectTo(REDIRECT_ERR);
}

redirectTo(REDIRECT_OK);
