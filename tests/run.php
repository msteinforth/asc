<?php
/**
 * Einfache Tests ohne Framework:  php tests/run.php
 * Arbeiten mit einem temporären Datenordner (tests/tmp), echte Daten bleiben unberührt.
 */
declare(strict_types=1);

$tmp = __DIR__ . '/tmp';
if (is_dir($tmp)) {
    exec('rm -rf ' . escapeshellarg($tmp));
}
mkdir($tmp, 0750, true);
copy(__DIR__ . '/../asc-data/tarife.json', "$tmp/tarife.json");

$GLOBALS['ASC_CONFIG_OVERRIDE'] = [
    'data_dir' => $tmp,
    'secret' => 'test-geheimnis-1234567890',
    'mail' => ['transport' => 'file'],
    'formular_min_sekunden' => 0,
];
require __DIR__ . '/../asc/lib/bootstrap.php';

$fehlgeschlagen = 0;
$anzahl = 0;
function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $fehlgeschlagen, $anzahl;
    $anzahl++;
    if ($ist === $soll) {
        echo "  ok   $name\n";
    } else {
        $fehlgeschlagen++;
        echo "  FEHL $name\n       erwartet: " . var_export($soll, true) . "\n       ist:      " . var_export($ist, true) . "\n";
    }
}

echo "Datum & Feiertage\n";
pruefe('Ostersonntag 2026', ostersonntag(2026), '2026-04-05');
pruefe('Ostersonntag 2027', ostersonntag(2027), '2027-03-28');
pruefe('Pfingstmontag 2026 ist Feiertag', ist_sonn_oder_feiertag('2026-05-25'), true);
pruefe('3. Oktober ist Feiertag', ist_sonn_oder_feiertag('2026-10-03'), true);
pruefe('Sonntag', ist_sonn_oder_feiertag('2026-11-01'), true);
pruefe('normaler Dienstag', ist_sonn_oder_feiertag('2026-11-03'), false);
pruefe('ungültiges Datum', ist_datum('2026-02-30'), false);
pruefe('Tage im Bereich', count(tage_im_bereich('2026-12-20', '2026-12-27')), 8);

echo "Preise\n";
$gassi = tarif_finden('gassi-60');
$p = preis_berechnen($gassi, ['datum' => '2026-11-03', 'anzahlTiere' => 1]);
pruefe('Gassi lang Grundpreis', $p['gesamt'], 8.0);
$p = preis_berechnen($gassi, ['datum' => '2026-11-03', 'anzahlTiere' => 2]);
pruefe('Gassi lang zweites Tier', $p['gesamt'], 11.0);
$p = preis_berechnen($gassi, ['datum' => '2026-11-01', 'anzahlTiere' => 1, 'optionen' => ['abholung-extern']]);
pruefe('Gassi Sonntag + Abholung', $p['gesamt'], 12.0);
$p = preis_berechnen($gassi, ['datum' => '2026-11-03', 'optionen' => ['gibt-es-nicht']]);
pruefe('unbekannte Option wird ignoriert', $p['gesamt'], 8.0);
$ferien = tarif_finden('ferien');
$p = preis_berechnen($ferien, ['von' => '2026-12-20', 'bis' => '2026-12-25', 'anzahlTiere' => 1]);
pruefe('Ferien 6 Tage ohne Staffel', $p['gesamt'], 72.0);
$p = preis_berechnen($ferien, ['von' => '2026-12-20', 'bis' => '2026-12-26', 'anzahlTiere' => 1]);
pruefe('Ferien 7 Tage mit Staffel', $p['gesamt'], 70.0);
$p = preis_berechnen($ferien, ['von' => '2026-12-20', 'bis' => '2026-12-26', 'anzahlTiere' => 2]);
pruefe('Ferien 7 Tage, 2 Tiere', $p['gesamt'], 91.0);
$we = tarif_finden('wochenende');
pruefe('Wochenende Fr–So', preis_berechnen($we, ['von' => '2026-11-06', 'bis' => '2026-11-08'])['gesamt'], 35.0);
pruefe('zwei Wochenenden (Fr–So + 4 Tage)', preis_berechnen($we, ['von' => '2026-11-06', 'bis' => '2026-11-12'])['gesamt'], 105.0);
$tag = tarif_finden('tagesbetreuung');
pruefe('Tagesbetreuung am Sonntag', preis_berechnen($tag, ['von' => '2026-11-01', 'bis' => '2026-11-01'])['gesamt'], 18.0);

echo "Verfügbarkeit\n";
$morgen = datum_plus(heute(), 1);
$wt = wochentag($morgen);
file_put_contents("$tmp/verfuegbarkeit.json", json_encode([
    'standardZeitfenster' => ['14:00-15:00', '15:00-16:00'],
    'vorlaufTage' => 1,
    'wochenVoraus' => 8,
    'zeitraeume' => [
        ['von' => heute(), 'bis' => datum_plus(heute(), 60), 'aktion' => 'freigeben', 'wochentage' => [$wt]],
        ['von' => datum_plus(heute(), 7), 'bis' => datum_plus(heute(), 7), 'aktion' => 'sperren', 'notiz' => 'Urlaub'],
    ],
    'tage' => [
        ['datum' => datum_plus(heute(), 14), 'gesperrt' => false, 'zeitfenster' => ['10:00-11:00', 'kaputt'], 'notiz' => ''],
        ['datum' => datum_plus(heute(), 2), 'gesperrt' => false, 'zeitfenster' => [], 'notiz' => 'Standard'],
    ],
]));
$v = verfuegbarkeit_laden();
pruefe('Zeitraum gibt Wochentag frei', tag_regel($v, $morgen)['zeitfenster'], ['14:00-15:00', '15:00-16:00']);
pruefe('anderer Wochentag nicht frei', tag_regel($v, datum_plus(heute(), 3))['frei'], false);
pruefe('Sperre überschreibt Freigabe', tag_regel($v, datum_plus(heute(), 7))['frei'], false);
pruefe('Sperre am 7. Tag', tag_regel($v, datum_plus(heute(), 7))['notiz'], 'Urlaub');
pruefe('Einzeltag mit eigenem Fenster, ungültige verworfen', tag_regel($v, datum_plus(heute(), 14))['zeitfenster'], ['10:00-11:00']);
pruefe('Einzeltag ohne Fenster nutzt Standard', tag_regel($v, datum_plus(heute(), 2))['zeitfenster'], ['14:00-15:00', '15:00-16:00']);
pruefe('heute nicht buchbar (Vorlauf)', zeitfenster_pruefen(heute(), '14:00-15:00') !== null, true);
pruefe('morgen buchbar', zeitfenster_pruefen($morgen, '14:00-15:00'), null);
pruefe('falsches Fenster', zeitfenster_pruefen($morgen, '16:00-17:00') !== null, true);
pruefe('max. drei Zeitfenster', count(zeitfenster_bereinigen(['08:00-09:00', '09:00-10:00', '10:00-11:00', '11:00-12:00'])), 3);

echo "Buchungsablauf\n";
$eingabe = [
    'datum' => $morgen, 'zeitfenster' => '14:00-15:00', 'tarifId' => 'gassi-30', 'anzahlTiere' => '1',
    'tierName' => 'Bruno', 'rasse' => 'Labrador', 'groesse' => 'gross', 'hinweise' => 'zieht',
    'name' => 'Anna Muster', 'telefon' => '0170 1234567', 'email' => 'anna@example.org',
    'strasse' => 'Musterweg 1', 'ort' => 'Musterstadt', 'datenschutz' => '1',
];
[$b, $f] = gassi_formular_pruefen($eingabe);
pruefe('gültige Gassi-Buchung', $f, []);
pruefe('Tierart aus Tarif', $b['tier']['art'], 'hund');
[, $f] = gassi_formular_pruefen(['datenschutz' => ''] + $eingabe);
pruefe('Datenschutz Pflicht', isset($f['datenschutz']), true);
[, $f] = gassi_formular_pruefen(['email' => 'kein-mail'] + $eingabe);
pruefe('E-Mail geprüft', isset($f['email']), true);
[, $f] = gassi_formular_pruefen(['tarifId' => 'ferien'] + $eingabe);
pruefe('Anfrage-Tarif nicht direkt buchbar', isset($f['tarifId']), true);

$b1 = buchung_anlegen($b);
$b2 = buchung_anlegen($b);
pruefe('ID-Schema', (bool) preg_match('/^b-\d{8}-001$/', $b1['id']), true);
pruefe('laufende Nummer', substr($b2['id'], -3), '002');
pruefe('Status offen', $b1['status'], 'offen');
pruefe('offene Buchung belegt nicht', zeitfenster_pruefen($morgen, '14:00-15:00'), null);
buchung_status_setzen($b1['id'], 'bestaetigt');
pruefe('bestätigte Buchung belegt', zeitfenster_pruefen($morgen, '14:00-15:00') !== null, true);
$doppelt = null;
try {
    buchung_status_setzen($b2['id'], 'bestaetigt');
} catch (DomainException $e) {
    $doppelt = $e->getMessage();
}
pruefe('Doppelbestätigung verhindert', $doppelt !== null, true);

echo "Signierte Links & Spam-Schutz\n";
$b1 = buchung_finden($b1['id']);
$sig = aktion_signatur($b1, 'bestaetigen');
pruefe('gültige Signatur', aktion_link_pruefen($b1, 'bestaetigen', $sig), null);
pruefe('Signatur passt nicht zu anderer Aktion', aktion_link_pruefen($b1, 'ablehnen', $sig) !== null, true);
$alt = $b1;
$alt['erstellt'] = date('Y-m-d\TH:i:s', strtotime('-8 days'));
$alt['token'] = buchung_token($alt['id'], $alt['erstellt']);
pruefe('abgelaufener Link', str_contains((string) aktion_link_pruefen($alt, 'bestaetigen', aktion_signatur($alt, 'bestaetigen')), 'abgelaufen'), true);
pruefe('Honeypot', spam_pruefen(['website' => 'x', 'formToken' => formular_token()]) !== null, true);
pruefe('gültiges Token', spam_pruefen(['formToken' => formular_token()]), null);
pruefe('gefälschtes Token', spam_pruefen(['formToken' => time() . '.aaaaaaaaaaaaaaaaaaaaaaaa']) !== null, true);
pruefe('altes Token', spam_pruefen(['formToken' => (string) (time() - 90000) . '.' . substr(hash_hmac('sha256', 'formular|' . (time() - 90000), geheimnis()), 0, 24)]) !== null, true);

echo "Betreuungsanfrage\n";
[$a, $f] = betreuung_formular_pruefen([
    'von' => datum_plus(heute(), 10), 'bis' => datum_plus(heute(), 16), 'tierart' => 'katze', 'anzahlTiere' => '1',
    'ortBetreuung' => 'beim-halter', 'tarifId' => 'ferien', 'freitext' => 'Tabletten',
] + $eingabe);
pruefe('gültige Anfrage (auch über gesperrte Tage)', $f, []);
pruefe('Schätzung 7 Tage Staffel', $a['preisGesamt'], 70.0);
[, $f] = betreuung_formular_pruefen(['von' => datum_plus(heute(), 5), 'bis' => datum_plus(heute(), 2), 'tierart' => 'hund', 'ortBetreuung' => 'bei-uns', 'tarifId' => 'ferien'] + $eingabe);
pruefe('bis vor von', isset($f['bis']), true);

echo "Anonymisierung & CSV\n";
json_aendern('buchungen.json', function (array $alle) {
    $alle[0]['datum'] = date('Y-m-d', strtotime('-13 months'));
    return $alle;
});
pruefe('eine Buchung anonymisiert', buchungen_anonymisieren(12), 1);
$anon = buchungen_laden()[0];
pruefe('Name entfernt', $anon['kunde']['name'], '');
pruefe('Preis bleibt', (float) $anon['preisGesamt'], 5.0);
pruefe('zweiter Lauf ändert nichts', buchungen_anonymisieren(12), 0);
$csv = fopen('php://memory', 'w+b');
buchungen_csv($csv, [['kunde' => ['name' => '=HYPERLINK("x")'] + $b['kunde']] + $b2]);
rewind($csv);
pruefe('CSV schützt vor Formeln', str_contains(stream_get_contents($csv), "'=HYPERLINK"), true);

echo "Mails\n";
$vorher = glob("$tmp/mails/*.eml") ?: [];
mail_familie_neu(buchung_finden($b2['id']));
$neu = array_values(array_diff(glob("$tmp/mails/*.eml") ?: [], $vorher));
pruefe('Familien-Mail geschrieben', count($neu), 1);
pruefe('enthält Bestätigungslink', str_contains((string) file_get_contents($neu[0] ?? '/dev/null'), 'aktion=bestaetigen&sig='), true);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$anzahl Prüfungen, $fehlgeschlagen fehlgeschlagen.\n";
exit($fehlgeschlagen ? 1 : 0);
