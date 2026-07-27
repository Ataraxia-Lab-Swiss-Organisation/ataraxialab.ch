<?php

declare(strict_types=1);

namespace Ataraxia\Mail;

/**
 * SmtpException — exception dédiée aux erreurs SMTP (S1 Sonar : éviter les
 * exceptions génériques). Étend RuntimeException pour rester non vérifiée.
 */
class SmtpException extends \RuntimeException {}

/**
 * SmtpMailer — client SMTP minimal (STARTTLS + AUTH LOGIN)
 *
 * Écrit pour l'hébergement mutualisé Infomaniak (mail() désactivé, pas de
 * pipeline Composer/vendor en place — voir technique/infomaniak_infra.md §9).
 * Alternative légère à PHPMailer : ~130 lignes, zéro dépendance externe,
 * surface d'attaque réduite, rien à mettre à jour pour des CVE tierces
 * (principe OWASP/12-factor du référentiel — README.md §7.2).
 *
 * Volontairement minimal : envoi texte brut, un destinataire, un Reply-To.
 * Pas d'HTML, pas de pièces jointes, pas de multi-destinataires.
 *
 * Utilisation :
 *   $mailer = new SmtpMailer('mail.infomaniak.com', 587, $user, $pass);
 *   $mailer->send($from, $fromName, $to, $replyTo, $subject, $body);
 */
final class SmtpMailer
{
    /** @var resource|false */
    private $socket = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * @throws SmtpException en cas d'échec à n'importe quelle étape
     */
    public function send(string $from, string $fromName, string $to, string $replyTo, string $subject, string $body): void
    {
        $this->assertSafeHeaderValue($fromName);
        $this->assertSafeHeaderValue($subject);

        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new SmtpException('adresse_email_invalide');
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new SmtpException('reply_to_invalide');
        }

        try {
            $this->connect();
            $this->command('EHLO ataraxialab.ch', [250]);
            $this->command('STARTTLS', [220]);

            // S4830 — forcer TLS 1.2+ uniquement (pas TLS 1.0/1.1)
            $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $tlsMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; // @phpstan-ignore-line
            }
            if (!stream_socket_enable_crypto($this->socket, true, $tlsMethod)) {
                throw new SmtpException('tls_echec');
            }

            $this->command('EHLO ataraxialab.ch', [250]);
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
            $this->command("MAIL FROM:<{$from}>", [250]);
            $this->command("RCPT TO:<{$to}>", [250, 251]);
            $this->command('DATA', [354]);

            $headers = array_filter([
                'From: ' . $this->encodeHeaderWord($fromName) . " <{$from}>",
                "To: <{$to}>",
                $replyTo !== '' ? "Reply-To: <{$replyTo}>" : null,
                'Subject: ' . $this->encodeHeaderWord($subject),
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: Ataraxia-SmtpMailer',
            ], static fn($h) => $h !== null);

            // Dot-stuffing SMTP : toute ligne commençant par un point doit être doublée
            $bodyEscaped = preg_replace('/^\./m', '..', $body);
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyEscaped . "\r\n.";

            $this->command($message, [250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        $remote = "tcp://{$this->host}:{$this->port}";
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new SmtpException("connexion_smtp_echouee: {$errstr} ({$errno})");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->readResponse([220]);
    }

    private function command(string $line, array $expectedCodes): string
    {
        fwrite($this->socket, $line . "\r\n");
        return $this->readResponse($expectedCodes);
    }

    private function readResponse(array $expectedCodes): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Ligne multi-lignes SMTP : "250-" continue, "250 " (espace) termine
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new SmtpException("smtp_code_inattendu_{$code}: " . trim($response));
        }
        return $response;
    }

    private function assertSafeHeaderValue(string $value): void
    {
        // Anti-injection d'en-têtes SMTP/mail (OWASP) : un CR/LF dans une valeur
        // destinée à un en-tête permettrait d'injecter des en-têtes/destinataires
        if (preg_match('/[\r\n]/', $value)) {
            throw new SmtpException('injection_entete_detectee');
        }
    }

    private function encodeHeaderWord(string $value): string
    {
        // Encodage RFC 2047 — évite tout souci d'accents dans les en-têtes
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = false;
    }
}
