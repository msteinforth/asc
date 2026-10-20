<?php
/** Liefert die buchbaren Gassi-Tage mit Zeitfenstern als JSON. */
require __DIR__ . '/../lib/bootstrap.php';

json_antwort(kalender_oeffentlich());
