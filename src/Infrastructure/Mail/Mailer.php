<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Thin SMTP wrapper around PHPMailer used for sensor alarm notifications.
 * Constructed directly (no DI) inside bin/sensors-daemon.php too, since
 * that script has no container — keep the constructor to a single plain
 * settings array so both call sites stay simple.
 */
final class Mailer
{
    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string} $settings
     */
    public function __construct(private readonly array $settings)
    {
    }

    public function isConfigured(): bool
    {
        return trim($this->settings['host']) !== '';
    }

    public function send(string $toEmail, ?string $toName, string $subject, string $body): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $this->settings['host'];
            $mailer->Port = $this->settings['port'];
            $mailer->SMTPAuth = trim($this->settings['username']) !== '';
            if ($mailer->SMTPAuth) {
                $mailer->Username = $this->settings['username'];
                $mailer->Password = $this->settings['password'];
            }
            $mailer->SMTPSecure = match ($this->settings['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($this->settings['from_address'], $this->settings['from_name']);
            $mailer->addAddress($toEmail, $toName ?? '');
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->isHTML(false);

            return $mailer->send();
        } catch (PHPMailerException) {
            return false;
        }
    }
}
