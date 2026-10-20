<?php
/** Liefert ein Formular-Token für den Spam-Schutz (Zeitprüfung). */
require __DIR__ . '/../lib/bootstrap.php';

json_antwort(['formToken' => formular_token()]);
