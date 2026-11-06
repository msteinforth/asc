<?php
/**
 * Konfiguration des ASC – Animal Sitter Club.
 *
 * Kopiere diese Datei nach `config.php` (im selben Ordner) und passe die Werte an.
 * `config.php` wird nicht ins Git-Repository eingecheckt.
 */
return [
    // Öffentliche Adresse der Seite, ohne abschließenden Schrägstrich.
    // Wird für die Bestätigen-/Ablehnen-Links in den E-Mails verwendet.
    'base_url' => 'http://localhost:8000',

    // Ordner mit tarife.json, verfuegbarkeit.json und buchungen.json.
    // Auf Strato idealerweise außerhalb des Webroots (z. B. /asc-data neben /asc).
    'data_dir' => __DIR__ . '/../../asc-data',

    // Geheimer Schlüssel für die signierten Links und den Spam-Schutz.
    // Leer lassen: Dann wird er beim ersten Aufruf zufällig erzeugt und als
    // <data_dir>/geheimnis.key gespeichert (bleibt über Uploads hinweg gleich).
    'secret' => '',

    // Gemeinsame Familien-Adresse: hier landen alle Buchungen und Anfragen.
    'familie_email' => 'asc@steinforth.com',
    'familie_name'  => 'ASC – Animal Sitter Club',

    // Kontakt, der in den Kunden-Mails genannt wird.
    'kontakt_telefon' => '0160 7003820',

    'mail' => [
        // 'file' = Mails werden nur als .eml-Datei in <data_dir>/mails abgelegt (lokale Entwicklung)
        // 'smtp' = Versand über PHPMailer und das Strato-Postfach
        // 'mail' = Versand über PHPs mail()-Funktion (Notlösung)
        'transport' => 'file',
        'from'      => 'asc@steinforth.com',
        'from_name' => 'ASC – Animal Sitter Club',
        'smtp' => [
            'host'     => 'smtp.strato.de',
            'port'     => 465,
            'secure'   => 'ssl', // 'ssl' (Port 465) oder 'tls' (Port 587)
            'user'     => 'asc@steinforth.com',
            'password' => '',
        ],
    ],

    // Gültigkeit der Bestätigen-/Ablehnen-Links in Tagen.
    'link_gueltig_tage' => 7,

    // Personenbezogene Daten abgeschlossener Buchungen nach so vielen Monaten anonymisieren.
    'anonymisieren_nach_monaten' => 12,

    // Spam-Schutz: Formular muss mindestens so viele Sekunden geöffnet gewesen sein.
    'formular_min_sekunden' => 3,

    // Zeitzone für Datum/Uhrzeit.
    'zeitzone' => 'Europe/Berlin',

    // Login für /admin (HTTP Basic Auth, von PHP geprüft). Auf dem Server Pflicht:
    // ohne diese Angaben bleibt der Admin-Bereich gesperrt.
    // Hash erzeugen mit: php -r "echo password_hash('meinpasswort', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_login' => [
        'user'          => 'admin',
        'password_hash' => '$2y$10$J/LBsaQDDBPnyPjaMCOguuzippyuweVNifvo/7OXJ.BxgM8IoAOKa', // lokal: admin / admin
    ],
];
