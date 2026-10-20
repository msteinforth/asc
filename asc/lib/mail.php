<?php
/**
 * E-Mail-Versand: SMTP über PHPMailer, mail() oder lokal als Datei.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sendet eine Text-Mail. Liefert true bei Erfolg. Fehler werden protokolliert,
 * damit eine Buchung nie an einem Mailproblem scheitert.
 */
function mail_senden(string $an, string $betreff, string $text, ?string $antwortAn = null): bool
{
    $cfg = config('mail') ?? [];
    $transport = $cfg['transport'] ?? 'file';
    $von = $cfg['from'] ?? config('familie_email');
    $vonName = $cfg['from_name'] ?? config('familie_name');

    try {
        if ($transport === 'file') {
            return mail_als_datei($an, $betreff, $text, $von, $vonName, $antwortAn);
        }

        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        if ($transport === 'smtp') {
            $smtp = $cfg['smtp'] ?? [];
            $mail->isSMTP();
            $mail->Host = $smtp['host'] ?? 'smtp.strato.de';
            $mail->Port = (int) ($smtp['port'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['user'] ?? '';
            $mail->Password = $smtp['password'] ?? '';
            $mail->SMTPSecure = ($smtp['secure'] ?? 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Timeout = 15;
        } else {
            $mail->isMail();
        }
        $mail->setFrom($von, $vonName);
        $mail->addAddress($an);
        if ($antwortAn) {
            $mail->addReplyTo($antwortAn);
        }
        $mail->Subject = $betreff;
        $mail->Body = $text;
        $mail->isHTML(false);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        mail_fehler_protokollieren($an, $betreff, $e->getMessage());
        return false;
    }
}

function mail_als_datei(string $an, string $betreff, string $text, string $von, string $vonName, ?string $antwortAn): bool
{
    $ordner = daten_pfad('mails');
    if (!is_dir($ordner)) {
        mkdir($ordner, 0750, true);
    }
    $kopf = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mb_encode_mimeheader($vonName) . " <$von>",
        "To: $an",
        'Subject: ' . mb_encode_mimeheader($betreff),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($antwortAn) {
        $kopf[] = "Reply-To: $antwortAn";
    }
    $name = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.eml';
    return file_put_contents("$ordner/$name", implode("\r\n", $kopf) . "\r\n\r\n" . $text) !== false;
}

function mail_fehler_protokollieren(string $an, string $betreff, string $fehler): void
{
    $zeile = sprintf("[%s] Mail an %s („%s“) fehlgeschlagen: %s\n", date('Y-m-d H:i:s'), $an, $betreff, $fehler);
    @file_put_contents(daten_pfad('mail-fehler.log'), $zeile, FILE_APPEND | LOCK_EX);
    error_log(trim($zeile));
}
