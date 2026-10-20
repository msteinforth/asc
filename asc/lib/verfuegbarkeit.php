<?php
/**
 * Verfügbarkeit für Gassi-Termine aus verfuegbarkeit.json.
 *
 * Aufbau der Datei:
 * {
 *   "standardZeitfenster": ["14:00-15:00", "15:00-16:00", "17:00-18:00"],
 *   "vorlaufTage": 1,         // frühestens so viele Tage im Voraus buchbar
 *   "wochenVoraus": 8,        // so weit reicht der Kalender
 *   "zeitraeume": [           // pauschale Regeln, werden der Reihe nach angewendet
 *     {"von": "2026-10-01", "bis": "2027-07-31", "aktion": "freigeben",
 *      "wochentage": [1, 3, 5], "zeitfenster": [...], "notiz": "Schulzeit"},
 *     {"von": "2026-12-21", "bis": "2027-01-06", "aktion": "sperren", "notiz": "Weihnachten"}
 *   ],
 *   "tage": [                 // einzelne Tage, überschreiben die Zeiträume
 *     {"datum": "2026-11-03", "zeitfenster": ["14:00-15:00"], "gesperrt": false, "notiz": ""}
 *   ]
 * }
 */
declare(strict_types=1);

const STANDARD_VERFUEGBARKEIT = [
    'standardZeitfenster' => ['14:00-15:00', '15:00-16:00', '17:00-18:00'],
    'vorlaufTage'         => 1,
    'wochenVoraus'        => 8,
    'zeitraeume'          => [],
    'tage'                => [],
];

const MAX_ZEITFENSTER_PRO_TAG = 3;

function verfuegbarkeit_laden(): array
{
    return array_replace(STANDARD_VERFUEGBARKEIT, json_lesen('verfuegbarkeit.json', []));
}

function verfuegbarkeit_aendern(callable $aendern): void
{
    json_aendern('verfuegbarkeit.json', function ($daten) use ($aendern) {
        return $aendern(array_replace(STANDARD_VERFUEGBARKEIT, is_array($daten) ? $daten : []));
    }, []);
}

function ist_zeitfenster(mixed $wert): bool
{
    if (!is_string($wert) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $wert)) {
        return false;
    }
    [$von, $bis] = explode('-', $wert);
    return $von < $bis;
}

/** Bereinigt eine Liste von Zeitfenstern (gültig, eindeutig, sortiert, max. 3). */
function zeitfenster_bereinigen(array $liste): array
{
    $liste = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $liste)), 'ist_zeitfenster')));
    sort($liste);
    return array_slice($liste, 0, MAX_ZEITFENSTER_PRO_TAG);
}

/**
 * Ermittelt für einen Tag den Zustand aus Regeln und Einzeltagen.
 * Liefert ['frei' => bool, 'zeitfenster' => [...], 'notiz' => string, 'quelle' => 'tag'|'zeitraum'|null]
 */
function tag_regel(array $v, string $datum): array
{
    $ergebnis = ['frei' => false, 'zeitfenster' => [], 'notiz' => '', 'quelle' => null];
    $wt = wochentag($datum);

    foreach ($v['zeitraeume'] as $z) {
        if (!ist_datum($z['von'] ?? null) || !ist_datum($z['bis'] ?? null)) {
            continue;
        }
        if ($datum < $z['von'] || $datum > $z['bis']) {
            continue;
        }
        if (!empty($z['wochentage']) && !in_array($wt, array_map('intval', $z['wochentage']), true)) {
            continue;
        }
        if (($z['aktion'] ?? '') === 'sperren') {
            $ergebnis = ['frei' => false, 'zeitfenster' => [], 'notiz' => $z['notiz'] ?? '', 'quelle' => 'zeitraum'];
        } else {
            $fenster = !empty($z['zeitfenster']) ? $z['zeitfenster'] : $v['standardZeitfenster'];
            $ergebnis = ['frei' => true, 'zeitfenster' => zeitfenster_bereinigen($fenster), 'notiz' => $z['notiz'] ?? '', 'quelle' => 'zeitraum'];
        }
    }

    foreach ($v['tage'] as $t) {
        if (($t['datum'] ?? null) !== $datum) {
            continue;
        }
        if (!empty($t['gesperrt'])) {
            $ergebnis = ['frei' => false, 'zeitfenster' => [], 'notiz' => $t['notiz'] ?? '', 'quelle' => 'tag'];
        } else {
            $fenster = !empty($t['zeitfenster']) ? $t['zeitfenster'] : $v['standardZeitfenster'];
            $ergebnis = ['frei' => true, 'zeitfenster' => zeitfenster_bereinigen($fenster), 'notiz' => $t['notiz'] ?? '', 'quelle' => 'tag'];
        }
    }

    if ($ergebnis['frei'] && !$ergebnis['zeitfenster']) {
        $ergebnis['frei'] = false;
    }
    return $ergebnis;
}

/** Zeitraum, in dem Gassi-Termine gebucht werden können: [erster Tag, letzter Tag]. */
function buchbarer_zeitraum(array $v): array
{
    $start = datum_plus(heute(), max(0, (int) $v['vorlaufTage']));
    $ende = datum_plus(heute(), max(1, (int) $v['wochenVoraus']) * 7);
    return [$start, $ende];
}

/** Bestätigte Gassi-Termine als ['Y-m-d' => ['14:00-15:00' => true]]. */
function belegte_zeitfenster(?array $buchungen = null): array
{
    $belegt = [];
    foreach ($buchungen ?? buchungen_laden() as $b) {
        if (($b['typ'] ?? '') === 'gassi' && in_array($b['status'] ?? '', ['bestaetigt', 'erledigt'], true)) {
            $belegt[$b['datum']][$b['zeitfenster']] = true;
        }
    }
    return $belegt;
}

/**
 * Kalender für die öffentliche Buchungsseite.
 * Liefert ['von', 'bis', 'tage' => ['Y-m-d' => ['fenster' => [['zeit', 'belegt'], …]]]]
 * Enthalten sind nur Tage mit mindestens einem Zeitfenster.
 */
function kalender_oeffentlich(): array
{
    $v = verfuegbarkeit_laden();
    [$von, $bis] = buchbarer_zeitraum($v);
    $belegt = belegte_zeitfenster();
    $tage = [];
    foreach (tage_im_bereich($von, $bis) as $datum) {
        $regel = tag_regel($v, $datum);
        if (!$regel['frei']) {
            continue;
        }
        $fenster = [];
        foreach ($regel['zeitfenster'] as $zf) {
            $fenster[] = ['zeit' => $zf, 'belegt' => isset($belegt[$datum][$zf])];
        }
        $tage[$datum] = ['fenster' => $fenster];
    }
    return ['von' => $von, 'bis' => $bis, 'tage' => $tage];
}

/** Prüft, ob ein Zeitfenster an einem Tag gebucht werden kann. Liefert Fehlertext oder null. */
function zeitfenster_pruefen(string $datum, string $zeitfenster): ?string
{
    $v = verfuegbarkeit_laden();
    [$von, $bis] = buchbarer_zeitraum($v);
    if ($datum < $von || $datum > $bis) {
        return 'Dieser Tag kann nicht (mehr) gebucht werden.';
    }
    $regel = tag_regel($v, $datum);
    if (!$regel['frei'] || !in_array($zeitfenster, $regel['zeitfenster'], true)) {
        return 'Dieses Zeitfenster ist an diesem Tag nicht frei.';
    }
    if (isset(belegte_zeitfenster()[$datum][$zeitfenster])) {
        return 'Dieses Zeitfenster ist leider schon belegt.';
    }
    return null;
}
