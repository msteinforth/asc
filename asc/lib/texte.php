<?php
/**
 * Texte der E-Mails. Hier können Formulierungen angepasst werden.
 */
declare(strict_types=1);

function buchung_zusammenfassung(array $b, bool $fuerFamilie = false): string
{
    $z = [];
    $z[] = $b['typ'] === 'gassi' ? 'Art:          Gassi-/Besuchstermin' : 'Art:          Betreuungsanfrage';
    $z[] = 'Termin:       ' . buchung_zeitraum_text($b);
    $z[] = 'Tarif:        ' . ($b['tarifName'] ?? $b['tarifId']);
    $z[] = 'Tier:         ' . trim(($b['tier']['name'] ?? '') . ' (' . (TIERARTEN[$b['tier']['art'] ?? ''] ?? $b['tier']['art'] ?? '') . ')');
    if (($b['anzahlTiere'] ?? 1) > 1) {
        $z[] = 'Anzahl Tiere: ' . $b['anzahlTiere'];
    }
    if (!empty($b['tier']['rasse']) || !empty($b['tier']['groesse'])) {
        $z[] = 'Rasse/Größe:  ' . trim(($b['tier']['rasse'] ?? '') . ' ' . ($b['tier']['groesse'] ?? ''));
    }
    if (!empty($b['ortBetreuung'])) {
        $z[] = 'Betreuung:    ' . ($b['ortBetreuung'] === 'bei-uns' ? 'bei uns' : 'beim Halter');
    }
    if (!empty($b['tier']['hinweise'])) {
        $z[] = "Besonderheiten:\n  " . str_replace("\n", "\n  ", $b['tier']['hinweise']);
    }
    if (!empty($b['freitext'])) {
        $z[] = "Hinweise:\n  " . str_replace("\n", "\n  ", $b['freitext']);
    }
    $z[] = '';
    $z[] = 'Preis:        ' . euro($b['preisGesamt']) . ($b['typ'] === 'betreuung' ? ' (unverbindliche Schätzung)' : '');
    foreach ($b['preisPosten'] ?? [] as $p) {
        $z[] = '  – ' . $p['text'] . ': ' . euro($p['betrag']);
    }
    if ($fuerFamilie) {
        $k = $b['kunde'];
        $z[] = '';
        $z[] = 'Kunde:        ' . $k['name'];
        $z[] = 'Telefon:      ' . $k['telefon'];
        $z[] = 'E-Mail:       ' . $k['email'];
        $z[] = 'Adresse:      ' . $k['strasse'] . ', ' . $k['ort'];
    }
    return implode("\n", $z);
}

function mail_signatur(): string
{
    return "\n\nViele Grüße\ndein ASC – Animal Sitter Club\n\n"
        . 'E-Mail: ' . config('familie_email') . "\n"
        . 'Telefon (Eltern): ' . config('kontakt_telefon') . "\n"
        . rtrim((string) config('base_url'), '/') . "\n";
}

/** Mail an die Familie mit allen Angaben und den Entscheidungs-Links. */
function mail_familie_neu(array $b): bool
{
    $art = $b['typ'] === 'gassi' ? 'Neue Gassi-Buchung' : 'Neue Betreuungsanfrage';
    $text = "$art {$b['id']} von {$b['kunde']['name']}\n\n"
        . buchung_zusammenfassung($b, true)
        . "\n\n----------------------------------------\n"
        . "Bestätigen:\n" . aktion_link($b, 'bestaetigen') . "\n\n"
        . "Ablehnen:\n" . aktion_link($b, 'ablehnen') . "\n\n"
        . 'Die Links sind ' . (int) config('link_gueltig_tage') . " Tage gültig. Danach im Admin-Bereich bearbeiten:\n"
        . rtrim((string) config('base_url'), '/') . "/admin/\n";
    return mail_senden((string) config('familie_email'), "[ASC] $art: " . buchung_zeitraum_text($b), $text, $b['kunde']['email']);
}

/** Eingangsbestätigung an den Kunden. */
function mail_kunde_eingang(array $b): bool
{
    $hallo = 'Hallo ' . $b['kunde']['name'] . ",\n\n";
    $einleitung = $b['typ'] === 'gassi'
        ? "danke für deine Buchung! Wir haben sie erhalten und bestätigen sie innerhalb von 24 Stunden per E-Mail. Erst dann ist der Termin verbindlich."
        : "danke für deine Anfrage! Wir schauen, ob wir die Betreuung übernehmen können, und melden uns innerhalb von 24 Stunden bei dir.";
    $text = $hallo . $einleitung . "\n\nDeine Angaben:\n\n" . buchung_zusammenfassung($b)
        . "\n\nBezahlt wird nach der Leistung, bar oder per Überweisung."
        . "\nFalls sich etwas ändert, antworte einfach auf diese Mail."
        . mail_signatur();
    return mail_senden($b['kunde']['email'], 'Deine Anfrage beim ASC ist eingegangen (' . $b['id'] . ')', $text, (string) config('familie_email'));
}

/** Bestätigung an den Kunden. */
function mail_kunde_bestaetigt(array $b, string $nachricht = ''): bool
{
    $text = 'Hallo ' . $b['kunde']['name'] . ",\n\n"
        . ($b['typ'] === 'gassi'
            ? "super, dein Termin ist bestätigt! Wir freuen uns auf " . ($b['tier']['name'] ?: 'dein Tier') . '.'
            : "super, wir übernehmen die Betreuung! Wir freuen uns auf " . ($b['tier']['name'] ?: 'dein Tier') . '.')
        . ($nachricht !== '' ? "\n\n" . $nachricht : '')
        . "\n\n" . buchung_zusammenfassung($b)
        . "\n\nBezahlt wird nach der Leistung, bar oder per Überweisung."
        . "\nAbsagen bis 24 Stunden vorher sind kostenlos – antworte einfach auf diese Mail oder ruf uns an."
        . "\nBitte gib uns für den Notfall eine Telefonnummer, unter der du während der Betreuung erreichbar bist."
        . mail_signatur();
    return mail_senden($b['kunde']['email'], 'Bestätigt: ' . buchung_zeitraum_text($b) . ' (' . $b['id'] . ')', $text, (string) config('familie_email'));
}

/** Absage an den Kunden. */
function mail_kunde_abgelehnt(array $b, string $nachricht = ''): bool
{
    $text = 'Hallo ' . $b['kunde']['name'] . ",\n\n"
        . 'leider können wir ' . ($b['typ'] === 'gassi' ? 'den Termin am ' : 'die Betreuung vom ') . buchung_zeitraum_text($b)
        . ' nicht übernehmen. Das tut uns leid!'
        . ($nachricht !== '' ? "\n\n" . $nachricht : '')
        . "\n\nVielleicht klappt es an einem anderen Tag – schau gern in unseren Kalender:\n"
        . rtrim((string) config('base_url'), '/') . ($b['typ'] === 'gassi' ? '/buchen' : '/anfrage')
        . mail_signatur();
    return mail_senden($b['kunde']['email'], 'Leider keine Zeit: ' . buchung_zeitraum_text($b) . ' (' . $b['id'] . ')', $text, (string) config('familie_email'));
}
