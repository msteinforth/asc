<?php
/**
 * Admin-Bereich des ASC: Buchungen, Verfügbarkeit, Tarife, CSV-Export.
 * Geschützt per .htaccess/.htpasswd (Strato) und optional zusätzlich per PHP (config 'admin_login').
 */
const ASC_ADMIN = true;
require __DIR__ . '/../lib/bootstrap.php';

admin_schutz();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
csrf_token(); // startet die Sitzung

$seite = $_GET['seite'] ?? 'buchungen';

/* ---------- Hilfen ---------- */

function weiter(string $ziel, string $meldung = '', string $art = 'erfolg'): never
{
    if ($meldung !== '') {
        $_SESSION['meldung'] = [$art, $meldung];
    }
    header('Location: ' . $ziel, true, 303);
    exit;
}

function status_etikett(string $status): string
{
    return '<span class="status status-' . h($status) . '">' . h(STATUS[$status] ?? $status) . '</span>';
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function zahl_eingabe(mixed $wert): float
{
    return round((float) str_replace(',', '.', (string) $wert), 2);
}

/* ---------- Aktionen (POST) ---------- */

if (($_GET['aktion'] ?? '') === 'export') {
    $buchungen = buchungen_laden();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="asc-buchungen-' . date('Y-m-d') . '.csv"');
    buchungen_csv(fopen('php://output', 'wb'), $buchungen);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_pruefen();
    $aktion = $_POST['aktion'] ?? '';
    try {
        switch ($aktion) {
            case 'status':
                $id = text_feld($_POST, 'id', 30);
                $status = text_feld($_POST, 'status', 20);
                $nachricht = text_feld($_POST, 'nachricht', 1000);
                $mailen = !empty($_POST['mailen']);
                $b = buchung_status_setzen($id, $status, 'im Admin-Bereich');
                $info = 'Status auf „' . STATUS[$status] . '“ gesetzt.';
                if ($mailen && $status === 'bestaetigt') {
                    $info .= mail_kunde_bestaetigt($b, $nachricht) ? ' Bestätigung an den Kunden gesendet.' : ' Achtung: Mail an den Kunden fehlgeschlagen.';
                } elseif ($mailen && $status === 'abgelehnt') {
                    $info .= mail_kunde_abgelehnt($b, $nachricht) ? ' Absage an den Kunden gesendet.' : ' Achtung: Mail an den Kunden fehlgeschlagen.';
                }
                weiter('?seite=buchung&id=' . rawurlencode($id), $info);

            case 'buchung-bearbeiten':
                $id = text_feld($_POST, 'id', 30);
                $preis = zahl_eingabe($_POST['preisGesamt'] ?? 0);
                $notiz = text_feld($_POST, 'adminNotiz', 2000);
                buchung_aendern($id, function (array $b) use ($preis, $notiz) {
                    if (abs($preis - (float) $b['preisGesamt']) > 0.001) {
                        $b['verlauf'][] = ['zeit' => date('Y-m-d\TH:i:s'), 'status' => $b['status'], 'notiz' => 'Preis geändert von ' . euro($b['preisGesamt']) . ' auf ' . euro($preis)];
                        $b['preisGesamt'] = $preis;
                        $b['preisAngepasst'] = true;
                    }
                    $b['adminNotiz'] = $notiz;
                    return $b;
                });
                weiter('?seite=buchung&id=' . rawurlencode($id), 'Gespeichert.');

            case 'tag':
                $datum = text_feld($_POST, 'datum', 10);
                if (!ist_datum($datum)) {
                    throw new DomainException('Ungültiges Datum.');
                }
                $modus = $_POST['modus'] ?? '';
                $fenster = zeitfenster_bereinigen(array_merge((array) ($_POST['fenster'] ?? []), (array) ($_POST['fensterNeu'] ?? [])));
                $notiz = text_feld($_POST, 'notiz', 120);
                verfuegbarkeit_aendern(function (array $v) use ($datum, $modus, $fenster, $notiz) {
                    $v['tage'] = array_values(array_filter($v['tage'], fn($t) => ($t['datum'] ?? '') !== $datum));
                    if ($modus === 'freigeben') {
                        if (!$fenster) {
                            throw new DomainException('Bitte mindestens ein Zeitfenster angeben (Format 14:00-15:00).');
                        }
                        $v['tage'][] = ['datum' => $datum, 'zeitfenster' => $fenster, 'gesperrt' => false, 'notiz' => $notiz];
                    } elseif ($modus === 'sperren') {
                        $v['tage'][] = ['datum' => $datum, 'zeitfenster' => [], 'gesperrt' => true, 'notiz' => $notiz];
                    }
                    usort($v['tage'], fn($a, $b) => strcmp($a['datum'], $b['datum']));
                    return $v;
                });
                $texte = ['freigeben' => 'freigegeben', 'sperren' => 'gesperrt', 'zuruecksetzen' => 'auf die allgemeinen Regeln zurückgesetzt'];
                weiter('?seite=kalender&monat=' . substr($datum, 0, 7) . '&tag=' . $datum, datum_de($datum) . ' ' . ($texte[$modus] ?? 'gespeichert') . '.');

            case 'zeitraum-neu':
                $von = text_feld($_POST, 'von', 10);
                $bis = text_feld($_POST, 'bis', 10);
                $art = ($_POST['art'] ?? '') === 'sperren' ? 'sperren' : 'freigeben';
                if (!ist_datum($von) || !ist_datum($bis) || $bis < $von) {
                    throw new DomainException('Bitte einen gültigen Zeitraum angeben.');
                }
                $wochentage = array_values(array_filter(array_map('intval', (array) ($_POST['wochentage'] ?? [])), fn($w) => $w >= 1 && $w <= 7));
                $fenster = zeitfenster_bereinigen((array) ($_POST['fensterNeu'] ?? []));
                $regel = ['von' => $von, 'bis' => $bis, 'aktion' => $art, 'notiz' => text_feld($_POST, 'notiz', 120)];
                if ($wochentage) {
                    $regel['wochentage'] = $wochentage;
                }
                if ($art === 'freigeben' && $fenster) {
                    $regel['zeitfenster'] = $fenster;
                }
                verfuegbarkeit_aendern(function (array $v) use ($regel) {
                    $v['zeitraeume'][] = $regel;
                    return $v;
                });
                weiter('?seite=kalender#zeitraeume', 'Zeitraum hinzugefügt.');

            case 'zeitraum-loeschen':
                $index = (int) ($_POST['index'] ?? -1);
                verfuegbarkeit_aendern(function (array $v) use ($index) {
                    if (!isset($v['zeitraeume'][$index])) {
                        throw new DomainException('Zeitraum nicht gefunden.');
                    }
                    array_splice($v['zeitraeume'], $index, 1);
                    return $v;
                });
                weiter('?seite=kalender#zeitraeume', 'Zeitraum gelöscht.');

            case 'einstellungen':
                $fenster = zeitfenster_bereinigen((array) ($_POST['standardZeitfenster'] ?? []));
                if (!$fenster) {
                    throw new DomainException('Bitte mindestens ein Standard-Zeitfenster angeben.');
                }
                verfuegbarkeit_aendern(function (array $v) use ($fenster) {
                    $v['standardZeitfenster'] = $fenster;
                    $v['vorlaufTage'] = max(0, min(14, (int) ($_POST['vorlaufTage'] ?? 1)));
                    $v['wochenVoraus'] = max(1, min(26, (int) ($_POST['wochenVoraus'] ?? 8)));
                    return $v;
                });
                weiter('?seite=kalender#einstellungen', 'Einstellungen gespeichert.');

            case 'tarife':
                $eingabe = (array) ($_POST['tarif'] ?? []);
                $tarife = array_map(function (array $t) use ($eingabe) {
                    $e = $eingabe[$t['id']] ?? null;
                    if (!is_array($e)) {
                        return $t;
                    }
                    $name = text_feld($e, 'name', 60);
                    if ($name === '') {
                        throw new DomainException('Jeder Tarif braucht einen Namen.');
                    }
                    $t['name'] = $name;
                    $t['beschreibung'] = text_feld($e, 'beschreibung', 200);
                    $t['einheit'] = text_feld($e, 'einheit', 30);
                    $t['preis'] = max(0, zahl_eingabe($e['preis'] ?? $t['preis']));
                    $t['aktiv'] = !empty($e['aktiv']);
                    if (isset($t['staffel'], $e['staffelAb'], $e['staffelPreis'])) {
                        $t['staffel'] = ['abTagen' => max(2, (int) $e['staffelAb']), 'preis' => max(0, zahl_eingabe($e['staffelPreis']))];
                    }
                    foreach ($t['zuschlaege'] ?? [] as $i => $z) {
                        if (isset($e['zuschlag'][$z['id']])) {
                            $t['zuschlaege'][$i]['preis'] = max(0, zahl_eingabe($e['zuschlag'][$z['id']]));
                        }
                    }
                    return $t;
                }, tarife_laden());
                tarife_speichern($tarife);
                weiter('?seite=tarife', 'Tarife gespeichert. Preisseite und Formulare zeigen sofort die neuen Werte.');

            case 'anonymisieren':
                $n = buchungen_anonymisieren();
                weiter('?seite=buchungen', $n ? "$n Buchung(en) anonymisiert." : 'Keine Buchungen älter als ' . (int) config('anonymisieren_nach_monaten') . ' Monate gefunden.');
        }
        weiter('?', 'Unbekannte Aktion.', 'fehler');
    } catch (DomainException $ex) {
        weiter($_SERVER['REQUEST_URI'], $ex->getMessage(), 'fehler');
    }
}

// Anonymisierung läuft bei jedem Aufruf des Admin-Bereichs automatisch mit.
buchungen_anonymisieren();

$meldung = $_SESSION['meldung'] ?? null;
unset($_SESSION['meldung']);

$buchungen = buchungen_laden();
$offen = count(array_filter($buchungen, fn($b) => $b['status'] === 'offen'));

/* ---------- Ausgabe ---------- */
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Admin – ASC</title>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="kopf">
  <div class="container kopf-innen">
    <a href="/admin/" class="logo-link"><img src="/assets/logo.svg" alt="ASC Admin" width="130" height="45"></a>
    <a href="/" class="klein">Zur Webseite</a>
  </div>
</header>
<main class="container" id="inhalt">
  <nav class="admin-nav" aria-label="Admin-Navigation">
    <a href="?seite=buchungen" <?= in_array($seite, ['buchungen', 'buchung'], true) ? 'aria-current="page"' : '' ?>>Buchungen<?= $offen ? " ($offen offen)" : '' ?></a>
    <a href="?seite=kalender" <?= $seite === 'kalender' ? 'aria-current="page"' : '' ?>>Verfügbarkeit</a>
    <a href="?seite=tarife" <?= $seite === 'tarife' ? 'aria-current="page"' : '' ?>>Tarife</a>
    <a href="?aktion=export">CSV-Export</a>
  </nav>

  <?php if ($meldung): ?>
    <div class="hinweis <?= h($meldung[0]) ?>" role="status"><?= h($meldung[1]) ?></div>
  <?php endif; ?>

<?php
switch ($seite) {
    case 'buchung':
        require __DIR__ . '/teile/buchung.php';
        break;
    case 'kalender':
        require __DIR__ . '/teile/kalender.php';
        break;
    case 'tarife':
        require __DIR__ . '/teile/tarife.php';
        break;
    default:
        require __DIR__ . '/teile/buchungen.php';
}
?>
</main>
</body>
</html>
