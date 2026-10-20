<?php
/**
 * Bestätigen/Ablehnen einer Buchung über den signierten Link aus der Familien-Mail.
 *
 * GET zeigt nur eine Rückfrage (Mailprogramme rufen Links manchmal automatisch auf),
 * erst das Absenden per POST ändert den Status und verschickt die Mail an den Kunden.
 */
require __DIR__ . '/../lib/bootstrap.php';

header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

$e = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$id = text_feld($e, 'id', 30);
$aktion = text_feld($e, 'aktion', 20);
$sig = text_feld($e, 'sig', 64);

$buchung = $id !== '' ? buchung_finden($id) : null;
$fehler = null;
$erfolg = null;
$linkOk = false;

if (!$buchung || !in_array($aktion, ['bestaetigen', 'ablehnen'], true)) {
    $fehler = 'Diese Buchung gibt es nicht (mehr).';
} elseif ($f = aktion_link_pruefen($buchung, $aktion, $sig)) {
    $fehler = $f;
} else {
    $linkOk = true;
}

if (!$linkOk) {
    // Fehler steht bereits fest; ohne gültigen Link werden keine Daten angezeigt.
} elseif ($buchung['status'] !== 'offen') {
    $fehler = 'Diese Buchung wurde bereits bearbeitet (Status: ' . STATUS[$buchung['status']] . ').';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nachricht = text_feld($_POST, 'nachricht', 1000);
    try {
        if ($aktion === 'bestaetigen') {
            $buchung = buchung_status_setzen($id, 'bestaetigt', 'per E-Mail-Link');
            $versendet = mail_kunde_bestaetigt($buchung, $nachricht);
            $erfolg = 'Bestätigt! ' . ($versendet ? 'Der Kunde hat eine Bestätigung per E-Mail bekommen.' : 'Achtung: Die Mail an den Kunden konnte nicht versendet werden.');
        } else {
            $buchung = buchung_status_setzen($id, 'abgelehnt', 'per E-Mail-Link');
            $versendet = mail_kunde_abgelehnt($buchung, $nachricht);
            $erfolg = 'Abgelehnt. ' . ($versendet ? 'Der Kunde hat eine kurze Absage per E-Mail bekommen.' : 'Achtung: Die Mail an den Kunden konnte nicht versendet werden.');
        }
    } catch (DomainException $ex) {
        $fehler = $ex->getMessage();
    }
}
$titel = $aktion === 'ablehnen' ? 'Buchung ablehnen' : 'Buchung bestätigen';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= h($titel) ?> – ASC</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<main class="container schmal">
  <p><a href="/" class="logo-link"><picture><source media="(prefers-color-scheme: dark)" srcset="/assets/logo-inverse.svg"><img src="/assets/logo.svg" alt="ASC – Animal Sitter Club" width="217" height="56"></picture></a></p>
  <h1><?= h($titel) ?></h1>

  <?php if ($erfolg): ?>
    <div class="hinweis erfolg" role="status"><?= h($erfolg) ?></div>
  <?php elseif ($fehler): ?>
    <div class="hinweis fehler" role="alert"><?= h($fehler) ?></div>
  <?php endif; ?>

  <?php if ($linkOk): ?>
    <div class="asc-card">
      <h2><?= h($buchung['id']) ?> · <?= h($buchung['kunde']['name']) ?></h2>
      <pre class="zusammenfassung"><?= h(buchung_zusammenfassung($buchung, true)) ?></pre>
    </div>
  <?php endif; ?>

  <?php if ($linkOk && !$fehler && !$erfolg): ?>
    <form method="post" class="formular">
      <input type="hidden" name="id" value="<?= h($id) ?>">
      <input type="hidden" name="aktion" value="<?= h($aktion) ?>">
      <input type="hidden" name="sig" value="<?= h($sig) ?>">
      <div class="feld">
        <label for="nachricht">Persönliche Nachricht an den Kunden (optional)</label>
        <textarea id="nachricht" name="nachricht" rows="3" placeholder="<?= $aktion === 'ablehnen' ? 'z. B. Am Wochenende davor hätten wir Zeit.' : 'z. B. Lena und Paul kommen um 14 Uhr.' ?>"></textarea>
      </div>
      <button type="submit" class="asc-btn <?= $aktion === 'ablehnen' ? 'asc-btn--ghost' : 'asc-btn--primary' ?>">
        <?= $aktion === 'ablehnen' ? 'Jetzt ablehnen' : 'Jetzt bestätigen' ?>
      </button>
    </form>
  <?php endif; ?>

  <p><a href="/admin/">Zum Admin-Bereich</a></p>
</main>
</body>
</html>
