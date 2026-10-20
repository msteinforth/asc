<?php
/**
 * Berechnet den Preis live für die Formulare.
 * Eingabe (JSON oder GET/POST): tarifId, datum | von+bis, anzahlTiere, optionen[]
 */
require __DIR__ . '/../lib/bootstrap.php';

$e = $_SERVER['REQUEST_METHOD'] === 'POST' ? eingabe() : $_GET;
$tarif = tarif_finden(text_feld($e, 'tarifId', 40));
if (!$tarif) {
    json_antwort(['ok' => false, 'fehler' => 'Unbekannter Tarif.'], 404);
}
$preis = preis_berechnen($tarif, [
    'datum'       => text_feld($e, 'datum', 10),
    'von'         => text_feld($e, 'von', 10),
    'bis'         => text_feld($e, 'bis', 10),
    'anzahlTiere' => (int) ($e['anzahlTiere'] ?? 1),
    'optionen'    => optionen_aus($e),
]);
json_antwort(['ok' => true] + $preis);
