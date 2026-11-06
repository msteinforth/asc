<?php
/**
 * Erzeugt asc/lib/config.php für den Server aus Umgebungsvariablen
 * (im Deploy-Workflow aus den GitHub-Secrets):
 *
 *   ASC_SMTP_PASSWORT   Passwort des Postfachs asc@steinforth.com (Pflicht)
 *   ASC_ADMIN_PASSWORT  Passwort für /admin (Pflicht, wird hier gehasht)
 *   ASC_ADMIN_BENUTZER  Benutzername für /admin (Standard: admin)
 *   ASC_BASE_URL        Adresse der Seite (Standard: https://asc.steinforth.com)
 *
 *   php tools/config-erzeugen.php ziel/config.php
 *
 * Der geheime Schlüssel bleibt leer: Er wird auf dem Server beim ersten
 * Aufruf erzeugt und im Datenordner gespeichert.
 */
declare(strict_types=1);

$ziel = $argv[1] ?? '';
if ($ziel === '') {
    fwrite(STDERR, "Aufruf: php tools/config-erzeugen.php ziel/config.php\n");
    exit(2);
}

$env = fn(string $name, ?string $standard = null) => ($w = getenv($name)) !== false && $w !== '' ? $w : $standard;

$fehlend = array_filter(['ASC_SMTP_PASSWORT', 'ASC_ADMIN_PASSWORT'], fn($n) => $env($n) === null);
if ($fehlend) {
    fwrite(STDERR, 'Fehlende Secrets: ' . implode(', ', $fehlend) . "\n");
    exit(1);
}
if (strlen($env('ASC_ADMIN_PASSWORT')) < 10) {
    fwrite(STDERR, "ASC_ADMIN_PASSWORT muss mindestens 10 Zeichen lang sein.\n");
    exit(1);
}

$wert = fn($w) => var_export($w, true);
$config = '<?php
// Automatisch erzeugt vom Deploy-Workflow (tools/config-erzeugen.php) – nicht von Hand ändern.
return [
    \'base_url\' => ' . $wert(rtrim($env('ASC_BASE_URL', 'https://asc.steinforth.com'), '/')) . ',
    \'data_dir\' => dirname(__DIR__, 2) . \'/asc-data\',
    \'secret\' => \'\',
    \'familie_email\' => \'asc@steinforth.com\',
    \'familie_name\' => \'ASC – Animal Sitter Club\',
    \'kontakt_telefon\' => \'0160 7003820\',
    \'mail\' => [
        \'transport\' => \'smtp\',
        \'from\' => \'asc@steinforth.com\',
        \'from_name\' => \'ASC – Animal Sitter Club\',
        \'smtp\' => [
            \'host\' => \'smtp.strato.de\',
            \'port\' => 465,
            \'secure\' => \'ssl\',
            \'user\' => \'asc@steinforth.com\',
            \'password\' => ' . $wert($env('ASC_SMTP_PASSWORT')) . ',
        ],
    ],
    \'link_gueltig_tage\' => 7,
    \'anonymisieren_nach_monaten\' => 12,
    \'formular_min_sekunden\' => 3,
    \'zeitzone\' => \'Europe/Berlin\',
    \'admin_login\' => [
        \'user\' => ' . $wert($env('ASC_ADMIN_BENUTZER', 'admin')) . ',
        \'password_hash\' => ' . $wert(password_hash($env('ASC_ADMIN_PASSWORT'), PASSWORD_DEFAULT)) . ',
    ],
];
';

if (!is_dir(dirname($ziel))) {
    mkdir(dirname($ziel), 0700, true);
}
file_put_contents($ziel, $config);
chmod($ziel, 0600);
echo "config.php erzeugt: $ziel\n";
