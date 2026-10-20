<?php
/** Liefert alle aktiven Tarife als JSON (für Preisseite und Formulare). */
require __DIR__ . '/../lib/bootstrap.php';

json_antwort([
    'tarife'    => array_map('tarif_oeffentlich', tarife_aktiv()),
    'tierarten' => TIERARTEN,
]);
