<?php
/**
 * Datenhaltung in JSON-Dateien mit Dateisperre (flock).
 */
declare(strict_types=1);

function daten_pfad(string $datei): string
{
    $ordner = rtrim((string) config('data_dir'), '/');
    if (!is_dir($ordner)) {
        mkdir($ordner, 0750, true);
    }
    return $ordner . '/' . $datei;
}

/** Liest eine JSON-Datei. Fehlt sie, wird $standard zurückgegeben. */
function json_lesen(string $datei, mixed $standard = []): mixed
{
    $pfad = daten_pfad($datei);
    if (!is_file($pfad)) {
        return $standard;
    }
    $fh = fopen($pfad, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Datei $datei kann nicht gelesen werden.");
    }
    try {
        flock($fh, LOCK_SH);
        $inhalt = stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    if ($inhalt === '' || $inhalt === false) {
        return $standard;
    }
    return json_decode($inhalt, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Liest, verändert und schreibt eine JSON-Datei unter exklusiver Sperre.
 * $aendern bekommt die aktuellen Daten und gibt die neuen Daten zurück –
 * oder mit_ergebnis($neueDaten, $ergebnis), dann liefert json_aendern $ergebnis.
 */
function json_aendern(string $datei, callable $aendern, mixed $standard = []): mixed
{
    $pfad = daten_pfad($datei);
    $fh = fopen($pfad, 'c+b');
    if ($fh === false) {
        throw new RuntimeException("Datei $datei kann nicht geschrieben werden.");
    }
    try {
        flock($fh, LOCK_EX);
        $inhalt = stream_get_contents($fh);
        $daten = ($inhalt === '' || $inhalt === false)
            ? $standard
            : json_decode($inhalt, true, 512, JSON_THROW_ON_ERROR);

        $rueckgabe = $aendern($daten);
        $ergebnis = null;
        if (is_array($rueckgabe) && array_key_exists('__daten', $rueckgabe)) {
            $ergebnis = $rueckgabe['__ergebnis'] ?? null;
            $rueckgabe = $rueckgabe['__daten'];
        }

        $json = json_encode($rueckgabe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json . "\n");
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    return $ergebnis;
}

/** Hilfsfunktion für json_aendern: Daten schreiben und zusätzlich ein Ergebnis zurückgeben. */
function mit_ergebnis(mixed $daten, mixed $ergebnis): array
{
    return ['__daten' => $daten, '__ergebnis' => $ergebnis];
}
