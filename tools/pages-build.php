<?php
/**
 * Baut eine statische Vorschau der Webseite für GitHub Pages:
 *
 *   php tools/pages-build.php _site /asc
 *
 * - kopiert HTML und Assets (ohne PHP, Admin und Bibliotheken)
 * - erzeugt api/tarife.json und api/verfuegbarkeit.json aus asc-data/
 * - setzt den Basis-Pfad vor alle absoluten Links (Projektseite unter /<repo>/)
 * - schaltet app.js per <meta name="asc-modus" content="demo"> in den Vorschau-Modus
 */
declare(strict_types=1);

$ziel = rtrim($argv[1] ?? '_site', '/');
$basis = rtrim($argv[2] ?? '', '/');
$quelle = __DIR__ . '/../asc';

$GLOBALS['ASC_CONFIG_OVERRIDE'] = [
    'data_dir' => __DIR__ . '/../asc-data',
    'secret'   => 'nur-fuer-den-statischen-build',
];
require $quelle . '/lib/bootstrap.php';

if (is_dir($ziel)) {
    exec('rm -rf ' . escapeshellarg($ziel));
}
mkdir("$ziel/assets", 0755, true);
mkdir("$ziel/api", 0755, true);

$dateien = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$quelle/assets", FilesystemIterator::SKIP_DOTS));
foreach ($dateien as $datei) {
    $relativ = substr($datei->getPathname(), strlen("$quelle/assets/"));
    if (!is_dir(dirname("$ziel/assets/$relativ"))) {
        mkdir(dirname("$ziel/assets/$relativ"), 0755, true);
    }
    copy($datei->getPathname(), "$ziel/assets/$relativ");
}

// Vorschau hat keine Buchungen: kein Zeitfenster ist belegt.
$json = fn($daten) => json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents("$ziel/api/tarife.json", $json([
    'tarife'    => array_map('tarif_oeffentlich', tarife_aktiv()),
    'tierarten' => TIERARTEN,
]));
file_put_contents("$ziel/api/verfuegbarkeit.json", $json(kalender_oeffentlich()));

foreach (glob("$quelle/*.html") as $datei) {
    $html = file_get_contents($datei);
    // Absolute Links und Quellen auf den Basis-Pfad umschreiben (mailto:, https: usw. bleiben)
    $html = preg_replace('#\b(href|src|srcset|action)="/(?!/)#', '$1="' . $basis . '/', $html);
    $html = str_replace(
        '<meta charset="utf-8">',
        '<meta charset="utf-8">' . "\n  " . '<meta name="asc-modus" content="demo">'
            . "\n  " . '<meta name="asc-basis" content="' . h($basis) . '">'
            . "\n  " . '<meta name="robots" content="noindex">',
        $html
    );
    file_put_contents("$ziel/" . basename($datei), $html);
}

// Kein Jekyll-Verarbeitung durch GitHub Pages
touch("$ziel/.nojekyll");

echo 'Vorschau gebaut: ' . count(glob("$ziel/*.html")) . " Seiten in $ziel (Basis: " . ($basis ?: '/') . ")\n";
