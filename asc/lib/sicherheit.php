<?php
/**
 * Signierte Links, Spam-Schutz und Admin-Schutz.
 */
declare(strict_types=1);

/**
 * Geheimer Schlüssel für signierte Links und Formular-Tokens.
 * Steht er nicht in der Konfiguration, wird er einmalig zufällig erzeugt und im
 * Datenordner gespeichert. So bleibt er über alle Uploads hinweg gleich und muss
 * nirgends eingetragen werden.
 */
function geheimnis(): string
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $s = (string) config('secret');
    if ($s === '') {
        $pfad = daten_pfad('geheimnis.key');
        if (!is_file($pfad)) {
            $fh = fopen($pfad, 'x');
            if ($fh !== false) {
                fwrite($fh, bin2hex(random_bytes(32)));
                fclose($fh);
                @chmod($pfad, 0600);
            }
        }
        $s = trim((string) file_get_contents($pfad));
    }
    if (strlen($s) < 16) {
        throw new RuntimeException('Kein gültiger geheimer Schlüssel (config "secret" oder Datei geheimnis.key).');
    }
    return $s;
}

/** Token einer Buchung (wird in der Buchung gespeichert). */
function buchung_token(string $id, string $erstellt): string
{
    return hash_hmac('sha256', "buchung|$id|$erstellt", geheimnis());
}

/** Signatur für eine Aktion (bestaetigen/ablehnen) auf einer Buchung. */
function aktion_signatur(array $buchung, string $aktion): string
{
    return substr(hash_hmac('sha256', "aktion|{$buchung['id']}|$aktion|{$buchung['token']}", geheimnis()), 0, 32);
}

function aktion_link(array $buchung, string $aktion): string
{
    return rtrim((string) config('base_url'), '/') . '/api/bestaetigen.php?' . http_build_query([
        'id'     => $buchung['id'],
        'aktion' => $aktion,
        'sig'    => aktion_signatur($buchung, $aktion),
    ]);
}

/** Prüft Signatur und Ablauf eines Bestätigungslinks. Liefert Fehlertext oder null. */
function aktion_link_pruefen(array $buchung, string $aktion, string $sig): ?string
{
    if (!hash_equals(aktion_signatur($buchung, $aktion), $sig)) {
        return 'Der Link ist ungültig.';
    }
    $tage = (int) (config('link_gueltig_tage') ?: 7);
    $ablauf = (new DateTimeImmutable($buchung['erstellt']))->modify("+$tage days");
    if (new DateTimeImmutable() > $ablauf) {
        return "Der Link ist abgelaufen (gültig $tage Tage). Bitte im Admin-Bereich bearbeiten.";
    }
    return null;
}

/** Formular-Token mit Zeitstempel für die Zeitprüfung des Spam-Schutzes. */
function formular_token(): string
{
    $zeit = (string) time();
    return $zeit . '.' . substr(hash_hmac('sha256', "formular|$zeit", geheimnis()), 0, 24);
}

/**
 * Spam-Schutz: Honeypot-Feld „website“ muss leer sein, das Formular-Token gültig
 * und mindestens N Sekunden, höchstens 24 Stunden alt. Liefert Fehlertext oder null.
 */
function spam_pruefen(array $eingabe): ?string
{
    if (trim((string) ($eingabe['website'] ?? '')) !== '') {
        return 'Die Anfrage wurde als Spam erkannt.';
    }
    $token = (string) ($eingabe['formToken'] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{24})$/', $token, $m)) {
        return 'Das Formular ist abgelaufen. Bitte lade die Seite neu.';
    }
    $erwartet = substr(hash_hmac('sha256', "formular|{$m[1]}", geheimnis()), 0, 24);
    if (!hash_equals($erwartet, $m[2])) {
        return 'Das Formular ist abgelaufen. Bitte lade die Seite neu.';
    }
    $alter = time() - (int) $m[1];
    if ($alter < (int) (config('formular_min_sekunden') ?? 3)) {
        return 'Das ging zu schnell. Bitte prüfe deine Angaben und sende das Formular erneut.';
    }
    if ($alter > 86400) {
        return 'Das Formular ist abgelaufen. Bitte lade die Seite neu.';
    }
    return null;
}

/**
 * Admin-Schutz in PHP (zusätzlich zu .htaccess). Greift nur, wenn in der
 * Konfiguration 'admin_login' gesetzt ist.
 */
function admin_schutz(): void
{
    $login = config('admin_login');
    if (empty($login['user']) || empty($login['password_hash'])) {
        // Ohne Login-Daten nur lokal offen; auf dem Server bleibt der Admin-Bereich gesperrt.
        if (PHP_SAPI === 'cli-server') {
            return;
        }
        http_response_code(503);
        exit('Admin-Zugang ist nicht eingerichtet (admin_login in config.php fehlt).');
    }
    // Wurde bereits per .htaccess/.htpasswd angemeldet, nicht doppelt fragen.
    if (!empty($_SERVER['REMOTE_USER']) || !empty($_SERVER['REDIRECT_REMOTE_USER'])) {
        return;
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    // Unter CGI/FastCGI (Strato) kommt der Header nur über die RewriteRule in .htaccess an
    $kopf = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($user === null && str_starts_with($kopf, 'Basic ')) {
        [$user, $pass] = array_pad(explode(':', (string) base64_decode(substr($kopf, 6)), 2), 2, null);
    }
    if ($user !== null && hash_equals((string) $login['user'], (string) $user) && password_verify((string) $pass, (string) $login['password_hash'])) {
        return;
    }
    header('WWW-Authenticate: Basic realm="ASC Admin", charset="UTF-8"');
    http_response_code(401);
    exit('Anmeldung erforderlich.');
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_pruefen(): void
{
    $erwartet = csrf_token();
    if (!hash_equals($erwartet, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}
