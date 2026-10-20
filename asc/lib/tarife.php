<?php
/**
 * Tarife aus tarife.json und Preisberechnung.
 *
 * Abrechnungsarten eines Tarifs:
 *   pro-termin     – fester Preis pro Termin (Gassi, Katzenbesuch)
 *   pro-tag        – Preis × Anzahl Tage (optional Staffelpreis ab N Tagen)
 *   pro-wochenende – Preis pro angefangenem 3-Tage-Block (Fr–So)
 *
 * Regeln für Zuschläge:
 *   pro-weiteres-tier – je weiterem Tier, bei Tagestarifen je Tag
 *   sonn-feiertag     – je Sonn-/Feiertag im Termin bzw. Zeitraum
 *   optional          – einmalig, wenn der Kunde die Option wählt (z. B. Abholung außerhalb)
 */
declare(strict_types=1);

const TIERARTEN = [
    'hund'     => 'Hund',
    'katze'    => 'Katze',
    'kleintier' => 'Kleintier',
    'sonstiges' => 'Sonstiges',
];

const ABRECHNUNGEN = ['pro-termin', 'pro-tag', 'pro-wochenende'];

function tarife_laden(): array
{
    $daten = json_lesen('tarife.json', ['tarife' => []]);
    return $daten['tarife'] ?? [];
}

function tarife_speichern(array $tarife): void
{
    json_aendern('tarife.json', function ($daten) use ($tarife) {
        $daten = is_array($daten) ? $daten : [];
        $daten['tarife'] = array_values($tarife);
        return $daten;
    }, ['tarife' => []]);
}

/** Aktive Tarife, optional gefiltert nach Buchungsart ('direkt' / 'anfrage'). */
function tarife_aktiv(?string $art = null): array
{
    return array_values(array_filter(
        tarife_laden(),
        fn($t) => !empty($t['aktiv']) && ($art === null || ($t['art'] ?? '') === $art)
    ));
}

function tarif_finden(string $id, bool $nurAktive = true): ?array
{
    foreach (tarife_laden() as $t) {
        if (($t['id'] ?? '') === $id && (!$nurAktive || !empty($t['aktiv']))) {
            return $t;
        }
    }
    return null;
}

/** Öffentliche Sicht auf einen Tarif (für die API). */
function tarif_oeffentlich(array $t): array
{
    return [
        'id'           => $t['id'],
        'name'         => $t['name'],
        'beschreibung' => $t['beschreibung'] ?? '',
        'art'          => $t['art'],
        'einheit'      => $t['einheit'] ?? '',
        'abrechnung'   => $t['abrechnung'] ?? 'pro-termin',
        'preis'        => (float) $t['preis'],
        'tierarten'    => $t['tierarten'] ?? [],
        'empfohlen'    => !empty($t['empfohlen']),
        'zuschlaege'   => array_map(fn($z) => [
            'id'    => $z['id'],
            'name'  => $z['name'],
            'preis' => (float) $z['preis'],
            'regel' => $z['regel'] ?? 'optional',
        ], $t['zuschlaege'] ?? []),
        'staffel'      => isset($t['staffel']) ? [
            'abTagen' => (int) $t['staffel']['abTagen'],
            'preis'   => (float) $t['staffel']['preis'],
        ] : null,
    ];
}

/**
 * Berechnet den Preis für einen Tarif.
 *
 * $e: ['datum' => 'Y-m-d'] (pro-termin) oder ['von' => …, 'bis' => …],
 *     'anzahlTiere' => int, 'optionen' => [zuschlagId, …]
 *
 * Liefert ['gesamt' => float, 'posten' => [[text, betrag], …], 'tage' => int, 'einheiten' => int]
 */
function preis_berechnen(array $tarif, array $e): array
{
    $abrechnung = $tarif['abrechnung'] ?? 'pro-termin';
    $anzahlTiere = max(1, min(10, (int) ($e['anzahlTiere'] ?? 1)));
    $optionen = array_map('strval', (array) ($e['optionen'] ?? []));

    if ($abrechnung === 'pro-termin') {
        $tage = !empty($e['datum']) && ist_datum($e['datum']) ? [$e['datum']] : [];
        $anzahlTage = 1;
    } else {
        $von = $e['von'] ?? '';
        $bis = $e['bis'] ?? '';
        $tage = (ist_datum($von) && ist_datum($bis) && $von <= $bis) ? tage_im_bereich($von, $bis) : [];
        $anzahlTage = max(1, count($tage));
    }

    $posten = [];
    $einheiten = 1;
    $grundpreis = (float) $tarif['preis'];

    switch ($abrechnung) {
        case 'pro-tag':
            $einheiten = $anzahlTage;
            if (!empty($tarif['staffel']) && $anzahlTage >= (int) $tarif['staffel']['abTagen']) {
                $grundpreis = (float) $tarif['staffel']['preis'];
                $text = sprintf('%s: %d %s × %s (Staffelpreis ab %d Tagen)', $tarif['name'], $anzahlTage, $anzahlTage === 1 ? 'Tag' : 'Tage', euro($grundpreis), (int) $tarif['staffel']['abTagen']);
            } else {
                $text = sprintf('%s: %d %s × %s', $tarif['name'], $anzahlTage, $anzahlTage === 1 ? 'Tag' : 'Tage', euro($grundpreis));
            }
            $posten[] = [$text, $grundpreis * $einheiten];
            break;
        case 'pro-wochenende':
            $einheiten = (int) ceil($anzahlTage / 3);
            $posten[] = [sprintf('%s: %d × %s', $tarif['name'], $einheiten, euro($grundpreis)), $grundpreis * $einheiten];
            break;
        default:
            $posten[] = [$tarif['name'] . ($tarif['einheit'] ? ' (' . $tarif['einheit'] . ')' : ''), $grundpreis];
    }

    foreach ($tarif['zuschlaege'] ?? [] as $z) {
        $preis = (float) $z['preis'];
        switch ($z['regel'] ?? 'optional') {
            case 'pro-weiteres-tier':
                if ($anzahlTiere > 1) {
                    $faktor = ($anzahlTiere - 1) * ($abrechnung === 'pro-termin' ? 1 : $einheiten);
                    $posten[] = [sprintf('%s: %d × %s', $z['name'], $faktor, euro($preis)), $preis * $faktor];
                }
                break;
            case 'sonn-feiertag':
                $anzahl = count(array_filter($tage, 'ist_sonn_oder_feiertag'));
                if ($anzahl > 0) {
                    $posten[] = [sprintf('%s: %d × %s', $z['name'], $anzahl, euro($preis)), $preis * $anzahl];
                }
                break;
            default:
                if (in_array($z['id'], $optionen, true)) {
                    $posten[] = [$z['name'], $preis];
                }
        }
    }

    $gesamt = round(array_sum(array_column($posten, 1)), 2);
    return [
        'gesamt'    => $gesamt,
        'posten'    => array_map(fn($p) => ['text' => $p[0], 'betrag' => round($p[1], 2)], $posten),
        'tage'      => $abrechnung === 'pro-termin' ? 1 : $anzahlTage,
        'einheiten' => $einheiten,
    ];
}
