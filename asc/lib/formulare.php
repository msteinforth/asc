<?php
/**
 * Auswertung und Prüfung der beiden öffentlichen Formulare.
 */
declare(strict_types=1);

/** Eingabe als Array: JSON-Body oder klassisches POST-Formular. */
function eingabe(): array
{
    $typ = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($typ, 'application/json')) {
        $daten = json_decode((string) file_get_contents('php://input'), true);
        return is_array($daten) ? $daten : [];
    }
    return $_POST;
}

function text_feld(array $e, string $name, int $max = 200): string
{
    $wert = $e[$name] ?? '';
    if (!is_string($wert) && !is_numeric($wert)) {
        return '';
    }
    $wert = trim(str_replace("\r\n", "\n", (string) $wert));
    // Steuerzeichen außer Zeilenumbruch und Tab entfernen
    $wert = (string) preg_replace('/[^\P{C}\n\t]/u', '', $wert);
    return mb_substr($wert, 0, $max);
}

/** Gemeinsame Kontaktdaten prüfen. Fehler landen in $fehler[feld]. */
function kunde_pruefen(array $e, array &$fehler): array
{
    $k = [
        'name'    => text_feld($e, 'name', 100),
        'telefon' => text_feld($e, 'telefon', 40),
        'email'   => text_feld($e, 'email', 150),
        'strasse' => text_feld($e, 'strasse', 120),
        'ort'     => text_feld($e, 'ort', 80),
    ];
    if ($k['name'] === '') {
        $fehler['name'] = 'Bitte gib deinen Namen an.';
    }
    if (!preg_match('/^[0-9 +\/()\-]{5,40}$/', $k['telefon'])) {
        $fehler['telefon'] = 'Bitte gib eine Telefonnummer an, unter der wir dich erreichen.';
    }
    if (!filter_var($k['email'], FILTER_VALIDATE_EMAIL)) {
        $fehler['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
    }
    if ($k['strasse'] === '') {
        $fehler['strasse'] = 'Bitte gib Straße und Hausnummer an.';
    }
    if ($k['ort'] === '') {
        $fehler['ort'] = 'Bitte gib deinen Ort an.';
    }
    $ok = $e['datenschutz'] ?? false;
    if (!($ok === true || $ok === 'on' || $ok === '1' || $ok === 1)) {
        $fehler['datenschutz'] = 'Bitte bestätige die Datenschutzhinweise.';
    }
    return $k;
}

function optionen_aus(array $e): array
{
    $o = $e['optionen'] ?? [];
    return array_values(array_filter(array_map('strval', is_array($o) ? $o : [$o])));
}

/** Prüft das Gassi-Formular. Liefert [buchung|null, fehler[]]. */
function gassi_formular_pruefen(array $e): array
{
    $fehler = [];
    $datum = text_feld($e, 'datum', 10);
    $zeitfenster = text_feld($e, 'zeitfenster', 11);
    $tarif = tarif_finden(text_feld($e, 'tarifId', 40));

    if (!$tarif || $tarif['art'] !== 'direkt') {
        $fehler['tarifId'] = 'Bitte wähle eine Leistung aus.';
    }
    if (!ist_datum($datum) || !ist_zeitfenster($zeitfenster)) {
        $fehler['datum'] = 'Bitte wähle im Kalender einen Tag und ein Zeitfenster.';
    } elseif ($meldung = zeitfenster_pruefen($datum, $zeitfenster)) {
        $fehler['datum'] = $meldung;
    }

    $anzahl = (int) ($e['anzahlTiere'] ?? 1);
    if ($anzahl < 1 || $anzahl > 3) {
        $fehler['anzahlTiere'] = 'Wir nehmen 1 bis 3 Tiere auf einmal mit.';
    }
    $tier = [
        'name'     => text_feld($e, 'tierName', 60),
        'art'      => $tarif['tierarten'][0] ?? 'hund',
        'rasse'    => text_feld($e, 'rasse', 80),
        'groesse'  => text_feld($e, 'groesse', 20),
        'hinweise' => text_feld($e, 'hinweise', 2000),
    ];
    if ($tier['name'] === '') {
        $fehler['tierName'] = 'Wie heißt dein Tier?';
    }
    $kunde = kunde_pruefen($e, $fehler);

    if ($fehler) {
        return [null, $fehler];
    }
    $optionen = optionen_aus($e);
    $preis = preis_berechnen($tarif, ['datum' => $datum, 'anzahlTiere' => $anzahl, 'optionen' => $optionen]);
    return [[
        'typ'          => 'gassi',
        'datum'        => $datum,
        'zeitfenster'  => $zeitfenster,
        'tarifId'      => $tarif['id'],
        'tarifName'    => $tarif['name'],
        'anzahlTiere'  => $anzahl,
        'optionen'     => $optionen,
        'preisGesamt'  => $preis['gesamt'],
        'preisPosten'  => $preis['posten'],
        'tier'         => $tier,
        'kunde'        => $kunde,
        'datenschutzOk' => ['ok' => true, 'zeit' => date('Y-m-d\TH:i:s')],
    ], []];
}

/** Prüft das Betreuungsformular. Liefert [buchung|null, fehler[]]. */
function betreuung_formular_pruefen(array $e): array
{
    $fehler = [];
    $von = text_feld($e, 'von', 10);
    $bis = text_feld($e, 'bis', 10);
    if (!ist_datum($von)) {
        $fehler['von'] = 'Bitte gib an, ab wann wir betreuen sollen.';
    } elseif ($von < heute()) {
        $fehler['von'] = 'Der Beginn darf nicht in der Vergangenheit liegen.';
    }
    if (!ist_datum($bis)) {
        $fehler['bis'] = 'Bitte gib an, bis wann wir betreuen sollen.';
    } elseif (ist_datum($von) && $bis < $von) {
        $fehler['bis'] = 'Das Ende muss nach dem Beginn liegen.';
    } elseif (ist_datum($von) && count(tage_im_bereich($von, $bis)) > 42) {
        $fehler['bis'] = 'Für mehr als 6 Wochen sprich uns bitte direkt an.';
    }

    $tierart = text_feld($e, 'tierart', 20);
    if (!isset(TIERARTEN[$tierart])) {
        $fehler['tierart'] = 'Bitte wähle die Tierart.';
    }
    $anzahl = (int) ($e['anzahlTiere'] ?? 1);
    if ($anzahl < 1 || $anzahl > 10) {
        $fehler['anzahlTiere'] = 'Bitte gib die Anzahl der Tiere an (1–10).';
    }
    $ort = text_feld($e, 'ortBetreuung', 20);
    if (!in_array($ort, ['bei-uns', 'beim-halter'], true)) {
        $fehler['ortBetreuung'] = 'Bitte wähle, wo die Betreuung stattfinden soll.';
    }
    $tarif = tarif_finden(text_feld($e, 'tarifId', 40));
    if (!$tarif || $tarif['art'] !== 'anfrage') {
        $fehler['tarifId'] = 'Bitte wähle einen Tarif.';
    }

    $tier = [
        'name'     => text_feld($e, 'tierName', 60),
        'art'      => $tierart,
        'rasse'    => text_feld($e, 'rasse', 80),
        'groesse'  => '',
        'hinweise' => '',
    ];
    $kunde = kunde_pruefen($e, $fehler);

    if ($fehler) {
        return [null, $fehler];
    }
    $optionen = optionen_aus($e);
    $preis = preis_berechnen($tarif, ['von' => $von, 'bis' => $bis, 'anzahlTiere' => $anzahl, 'optionen' => $optionen]);
    return [[
        'typ'          => 'betreuung',
        'von'          => $von,
        'bis'          => $bis,
        'tarifId'      => $tarif['id'],
        'tarifName'    => $tarif['name'],
        'anzahlTiere'  => $anzahl,
        'optionen'     => $optionen,
        'ortBetreuung' => $ort,
        'preisGesamt'  => $preis['gesamt'],
        'preisPosten'  => $preis['posten'],
        'tier'         => $tier,
        'freitext'     => text_feld($e, 'freitext', 3000),
        'kunde'        => $kunde,
        'datenschutzOk' => ['ok' => true, 'zeit' => date('Y-m-d\TH:i:s')],
    ], []];
}

/**
 * Gemeinsamer Ablauf für beide Formulare: Spam-Schutz, Prüfung, Speichern, Mails.
 * Antwortet mit JSON (fetch) oder leitet weiter (Formular ohne JavaScript).
 */
function formular_verarbeiten(callable $pruefen): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_antwort(['ok' => false, 'fehler' => ['_' => 'Nur POST erlaubt.']], 405);
    }
    $e = eingabe();
    $alsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    // Erst die Felder prüfen, damit Tippfehler nicht hinter dem Spam-Schutz verschwinden
    [$buchung, $fehler] = $pruefen($e);
    if (!$fehler && ($spam = spam_pruefen($e))) {
        $fehler = ['_' => $spam];
    }
    if (!$fehler) {
        try {
            $buchung = buchung_anlegen($buchung, function (array $alle) use ($buchung) {
                // Doppelte Absendung (gleiche Mail, gleicher Termin, innerhalb von 10 Minuten) abfangen
                foreach ($alle as $b) {
                    if ($b['kunde']['email'] === $buchung['kunde']['email'] && $b['typ'] === $buchung['typ']
                        && ($b['datum'] ?? $b['von']) === ($buchung['datum'] ?? $buchung['von'])
                        && ($b['zeitfenster'] ?? $b['bis']) === ($buchung['zeitfenster'] ?? $buchung['bis'])
                        && strtotime($b['erstellt']) > time() - 600) {
                        return 'Diese Anfrage haben wir gerade schon erhalten.';
                    }
                }
                return null;
            });
        } catch (DomainException $ex) {
            $fehler = ['_' => $ex->getMessage()];
        }
    }

    if ($fehler) {
        if ($alsJson) {
            json_antwort(['ok' => false, 'fehler' => $fehler], 422);
        }
        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Fehler</title><p>Bitte prüfe deine Angaben:</p><ul>';
        foreach ($fehler as $f) {
            echo '<li>' . h($f) . '</li>';
        }
        echo '</ul><p><a href="javascript:history.back()">Zurück zum Formular</a></p>';
        exit;
    }

    mail_familie_neu($buchung);
    mail_kunde_eingang($buchung);
    buchungen_anonymisieren();

    $ziel = '/danke?typ=' . $buchung['typ'] . '&id=' . rawurlencode($buchung['id']);
    if ($alsJson) {
        json_antwort(['ok' => true, 'id' => $buchung['id'], 'weiter' => $ziel]);
    }
    header('Location: ' . $ziel, true, 303);
    exit;
}
