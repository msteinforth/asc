<?php
/**
 * Buchungen und Anfragen in buchungen.json.
 */
declare(strict_types=1);

const STATUS = [
    'offen'      => 'offen',
    'bestaetigt' => 'bestätigt',
    'abgelehnt'  => 'abgelehnt',
    'erledigt'   => 'erledigt',
];

function buchungen_laden(): array
{
    return json_lesen('buchungen.json', []);
}

function buchung_finden(string $id): ?array
{
    foreach (buchungen_laden() as $b) {
        if (($b['id'] ?? '') === $id) {
            return $b;
        }
    }
    return null;
}

/**
 * Legt eine neue Buchung an. ID nach Muster b-YYYYMMDD-NNN (laufende Nummer pro Tag).
 * Optional prüft $pruefen($buchungen) unter derselben Sperre und liefert einen Fehlertext.
 */
function buchung_anlegen(array $buchung, ?callable $pruefen = null): array
{
    return json_aendern('buchungen.json', function (array $buchungen) use ($buchung, $pruefen) {
        if ($pruefen && ($fehler = $pruefen($buchungen))) {
            throw new DomainException($fehler);
        }
        $praefix = 'b-' . date('Ymd') . '-';
        $nr = 0;
        foreach ($buchungen as $b) {
            if (str_starts_with($b['id'], $praefix)) {
                $nr = max($nr, (int) substr($b['id'], strlen($praefix)));
            }
        }
        $buchung['id'] = $praefix . sprintf('%03d', $nr + 1);
        $buchung['erstellt'] = date('Y-m-d\TH:i:s');
        $buchung['status'] = 'offen';
        $buchung['token'] = buchung_token($buchung['id'], $buchung['erstellt']);
        $buchung['verlauf'] = [['zeit' => $buchung['erstellt'], 'status' => 'offen', 'notiz' => 'Eingegangen über die Webseite']];
        $buchungen[] = $buchung;
        return mit_ergebnis($buchungen, $buchung);
    }, []);
}

/**
 * Ändert eine Buchung unter Sperre. $aendern bekommt die Buchung und alle Buchungen
 * und gibt die geänderte Buchung zurück (oder wirft DomainException).
 */
function buchung_aendern(string $id, callable $aendern): array
{
    return json_aendern('buchungen.json', function (array $buchungen) use ($id, $aendern) {
        foreach ($buchungen as $i => $b) {
            if ($b['id'] === $id) {
                $buchungen[$i] = $aendern($b, $buchungen);
                return mit_ergebnis($buchungen, $buchungen[$i]);
            }
        }
        throw new DomainException('Buchung nicht gefunden.');
    }, []);
}

/** Setzt den Status einer Buchung und protokolliert die Änderung. */
function buchung_status_setzen(string $id, string $status, string $notiz = ''): array
{
    if (!isset(STATUS[$status])) {
        throw new DomainException('Unbekannter Status.');
    }
    return buchung_aendern($id, function (array $b, array $alle) use ($status, $notiz) {
        if ($status === 'bestaetigt' && $b['typ'] === 'gassi') {
            foreach ($alle as $andere) {
                if ($andere['id'] !== $b['id'] && $andere['typ'] === 'gassi'
                    && in_array($andere['status'], ['bestaetigt', 'erledigt'], true)
                    && $andere['datum'] === $b['datum'] && $andere['zeitfenster'] === $b['zeitfenster']) {
                    throw new DomainException('Das Zeitfenster ist bereits durch Buchung ' . $andere['id'] . ' belegt.');
                }
            }
        }
        $b['status'] = $status;
        $b['verlauf'][] = ['zeit' => date('Y-m-d\TH:i:s'), 'status' => $status, 'notiz' => $notiz];
        return $b;
    });
}

/** Letzter Tag der Leistung (für Anonymisierung und Sortierung). */
function buchung_leistungsende(array $b): string
{
    return $b['typ'] === 'gassi' ? ($b['datum'] ?? '') : ($b['bis'] ?? '');
}

function buchung_zeitraum_text(array $b): string
{
    if ($b['typ'] === 'gassi') {
        return datum_de($b['datum']) . ', ' . str_replace('-', '–', $b['zeitfenster']) . ' Uhr';
    }
    return datum_de($b['von']) . ' bis ' . datum_de($b['bis']);
}

/**
 * Entfernt personenbezogene Daten aus Buchungen, deren Leistung länger als
 * N Monate zurückliegt. Statistik (Typ, Tarif, Preis, Datum, Tierart) bleibt.
 * Liefert die Anzahl der anonymisierten Buchungen.
 */
function buchungen_anonymisieren(?int $monate = null): int
{
    $monate ??= (int) (config('anonymisieren_nach_monaten') ?: 12);
    $grenze = (new DateTimeImmutable(heute()))->modify("-$monate months")->format('Y-m-d');
    $pfad = daten_pfad('buchungen.json');
    if (!is_file($pfad)) {
        return 0;
    }
    return (int) json_aendern('buchungen.json', function (array $buchungen) use ($grenze) {
        $anzahl = 0;
        foreach ($buchungen as $i => $b) {
            if (!empty($b['anonymisiert'])) {
                continue;
            }
            $ende = buchung_leistungsende($b) ?: substr($b['erstellt'], 0, 10);
            if ($ende >= $grenze) {
                continue;
            }
            $b['kunde'] = ['name' => '', 'telefon' => '', 'email' => '', 'strasse' => '', 'ort' => ''];
            $b['tier'] = ['name' => '', 'art' => $b['tier']['art'] ?? '', 'rasse' => '', 'groesse' => '', 'hinweise' => ''];
            $b['freitext'] = '';
            $b['adminNotiz'] = '';
            $b['anonymisiert'] = date('Y-m-d\TH:i:s');
            $buchungen[$i] = $b;
            $anzahl++;
        }
        return mit_ergebnis($buchungen, $anzahl);
    }, []);
}

/** Schreibt alle Buchungen als CSV (Semikolon, UTF-8 mit BOM für Excel). */
function buchungen_csv($ausgabe, array $buchungen): void
{
    fwrite($ausgabe, "\xEF\xBB\xBF");
    $spalten = ['ID', 'Typ', 'Status', 'Erstellt', 'Datum', 'Zeitfenster', 'Von', 'Bis', 'Tarif', 'Preis gesamt',
        'Tiername', 'Tierart', 'Anzahl Tiere', 'Rasse', 'Größe', 'Hinweise', 'Ort Betreuung', 'Freitext',
        'Kunde', 'Telefon', 'E-Mail', 'Straße', 'Ort', 'Datenschutz bestätigt', 'Anonymisiert'];
    fputcsv($ausgabe, $spalten, ';', '"', '');
    foreach ($buchungen as $b) {
        $zeile = [
            $b['id'], $b['typ'], $b['status'], $b['erstellt'],
            $b['datum'] ?? '', $b['zeitfenster'] ?? '', $b['von'] ?? '', $b['bis'] ?? '',
            $b['tarifName'] ?? $b['tarifId'], number_format((float) $b['preisGesamt'], 2, ',', ''),
            $b['tier']['name'] ?? '', $b['tier']['art'] ?? '', $b['anzahlTiere'] ?? 1, $b['tier']['rasse'] ?? '',
            $b['tier']['groesse'] ?? '', $b['tier']['hinweise'] ?? '', $b['ortBetreuung'] ?? '', $b['freitext'] ?? '',
            $b['kunde']['name'] ?? '', $b['kunde']['telefon'] ?? '', $b['kunde']['email'] ?? '',
            $b['kunde']['strasse'] ?? '', $b['kunde']['ort'] ?? '',
            $b['datenschutzOk']['zeit'] ?? '', $b['anonymisiert'] ?? '',
        ];
        // Schutz vor Formel-Injection in Excel
        $zeile = array_map(fn($w) => is_string($w) && preg_match('/^[=+\-@\t\r]/', $w) ? "'" . $w : $w, $zeile);
        fputcsv($ausgabe, $zeile, ';', '"', '');
    }
}
