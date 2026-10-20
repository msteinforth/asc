<?php
/**
 * Datums-Hilfen: Validierung, Feiertage, Bereiche.
 */
declare(strict_types=1);

/** Prüft ein Datum im Format YYYY-MM-DD. */
function ist_datum(mixed $wert): bool
{
    if (!is_string($wert) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert)) {
        return false;
    }
    [$j, $m, $t] = array_map('intval', explode('-', $wert));
    return checkdate($m, $t, $j);
}

function heute(): string
{
    return date('Y-m-d');
}

function datum_plus(string $datum, int $tage): string
{
    return (new DateTimeImmutable($datum))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');
}

/** Alle Tage von $von bis $bis (jeweils einschließlich). */
function tage_im_bereich(string $von, string $bis): array
{
    $tage = [];
    $d = new DateTimeImmutable($von);
    $ende = new DateTimeImmutable($bis);
    while ($d <= $ende) {
        $tage[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $tage;
}

/** Wochentag nach ISO: 1 = Montag … 7 = Sonntag. */
function wochentag(string $datum): int
{
    return (int) (new DateTimeImmutable($datum))->format('N');
}

/** Ostersonntag nach der Gaußschen Osterformel (ohne calendar-Erweiterung). */
function ostersonntag(int $jahr): string
{
    $a = $jahr % 19;
    $b = intdiv($jahr, 100);
    $c = $jahr % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $monat = intdiv($h + $l - 7 * $m + 114, 31);
    $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
}

/** Bundesweite gesetzliche Feiertage eines Jahres als [datum => name]. */
function feiertage(int $jahr): array
{
    static $cache = [];
    if (isset($cache[$jahr])) {
        return $cache[$jahr];
    }
    $ostern = ostersonntag($jahr);
    $liste = [
        "$jahr-01-01" => 'Neujahr',
        datum_plus($ostern, -2) => 'Karfreitag',
        datum_plus($ostern, 1) => 'Ostermontag',
        "$jahr-05-01" => 'Tag der Arbeit',
        datum_plus($ostern, 39) => 'Christi Himmelfahrt',
        datum_plus($ostern, 50) => 'Pfingstmontag',
        "$jahr-10-03" => 'Tag der Deutschen Einheit',
        "$jahr-12-25" => '1. Weihnachtstag',
        "$jahr-12-26" => '2. Weihnachtstag',
    ];
    return $cache[$jahr] = $liste;
}

function ist_sonn_oder_feiertag(string $datum): bool
{
    if (wochentag($datum) === 7) {
        return true;
    }
    return isset(feiertage((int) substr($datum, 0, 4))[$datum]);
}

/** Formatiert YYYY-MM-DD als z. B. „Di, 03.11.2026“. */
function datum_de(string $datum, bool $mitWochentag = true): string
{
    if (!ist_datum($datum)) {
        return $datum;
    }
    $namen = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    $d = new DateTimeImmutable($datum);
    return ($mitWochentag ? $namen[(int) $d->format('N')] . ', ' : '') . $d->format('d.m.Y');
}
