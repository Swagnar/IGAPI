<?php
namespace Swagnar\Igapi\Helper;

use PHPMailer\PHPMailer\PHPMailer;
use Swagnar\Igapi\Http\JsonClient;

/**
 * Mail z alertem po porannym runie, gdy coś poszło nie tak: błąd modułu, źródła, publikacji, wygasły token
 * albo przerwanie runu fatalem. Lista problemów pochodzi z Console (ERROR/FATAL), do treści dołączamy końcówkę logu.
 *
 * .env: ALERT_EMAIL (adresat) i SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS (skrzynka nadawcy).
 * Bez SMTP_HOST wysyła przez mail() serwera, bez ALERT_EMAIL — nic nie wysyła.
 */
final class Alert
{
    /** Wysyła alert, jeśli w tym uruchomieniu były błędy. Zwraca true, gdy mail poszedł. */
    public static function sendIfProblems(string $context): bool
    {
        $problems = Console::problems();
        if (!$problems) {
            return false;
        }

        $subject = sprintf('IGAPI: %d %s — %s', count($problems), self::plural(count($problems)), $context);
        $body    = "Problemy:\n\n" . implode("\n", array_map(fn($p) => '• ' . $p, array_unique($problems)))
                 . "\n\n---------------------------------------- log\n\n" . implode("\n", Console::log());
        return self::send($subject, $body);
    }

    public static function send(string $subject, string $body): bool
    {
        $to = trim((string) ($_ENV['ALERT_EMAIL'] ?? ''));
        if ($to === '') {
            Console::warn('Alert: brak ALERT_EMAIL w .env — mail nie poszedł');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $from = trim((string) ($_ENV['SMTP_USER'] ?? '')) ?: 'igapi@' . (gethostname() ?: 'localhost');

            if (!empty($_ENV['SMTP_HOST'])) {
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 465);
                $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USER'] ?? '';
                $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
                $mail->Timeout    = 20;
            } else {
                $mail->isMail();
            }

            $mail->setFrom($from, 'IGAPI');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            // Log może zawierać URL-e z kluczami — maskujemy jak w cache
            $mail->Body    = JsonClient::maskSecrets($body);
            $mail->send();
        } catch (\Throwable $e) {
            Console::warn('Alert: nie udało się wysłać maila — ' . JsonClient::maskSecrets($e->getMessage()));
            return false;
        }

        Console::info("Alert: wysłano mail na {$to}");
        return true;
    }

    private static function plural(int $n): string
    {
        return $n === 1 ? 'problem' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 'problemy' : 'problemów');
    }
}
