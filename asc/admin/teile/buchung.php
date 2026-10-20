<?php
/** Detailansicht einer Buchung mit Statusänderung. */
defined('ASC_ADMIN') || exit;

$b = buchung_finden((string) ($_GET['id'] ?? ''));
if (!$b) {
    echo '<p class="hinweis fehler">Buchung nicht gefunden.</p><p><a href="?seite=buchungen">Zur Liste</a></p>';
    return;
}
$k = $b['kunde'];
$t = $b['tier'];

// Überschneidungen: weitere bestätigte Termine am selben Tag bzw. im Zeitraum
$ueberschneidungen = array_filter($buchungen, function ($a) use ($b) {
    if ($a['id'] === $b['id'] || !in_array($a['status'], ['bestaetigt', 'offen'], true)) {
        return false;
    }
    $aVon = $a['typ'] === 'gassi' ? $a['datum'] : $a['von'];
    $aBis = $a['typ'] === 'gassi' ? $a['datum'] : $a['bis'];
    $bVon = $b['typ'] === 'gassi' ? $b['datum'] : $b['von'];
    $bBis = $b['typ'] === 'gassi' ? $b['datum'] : $b['bis'];
    return $aVon <= $bBis && $bVon <= $aBis;
});

// Gesperrte Tage im Betreuungszeitraum (dürfen angefragt werden, die Familie entscheidet)
$gesperrteTage = [];
if ($b['typ'] === 'betreuung') {
    $v = verfuegbarkeit_laden();
    foreach (tage_im_bereich($b['von'], $b['bis']) as $d) {
        foreach ($v['tage'] as $tag) {
            if ($tag['datum'] === $d && !empty($tag['gesperrt'])) {
                $gesperrteTage[] = datum_de($d) . ($tag['notiz'] ? ' (' . $tag['notiz'] . ')' : '');
            }
        }
        foreach ($v['zeitraeume'] as $z) {
            if (($z['aktion'] ?? '') === 'sperren' && $d >= $z['von'] && $d <= $z['bis']) {
                $gesperrteTage[] = datum_de($d) . ($z['notiz'] ? ' (' . $z['notiz'] . ')' : '');
                break;
            }
        }
    }
    $gesperrteTage = array_values(array_unique($gesperrteTage));
}
?>
<p><a href="?seite=buchungen">← Zur Liste</a></p>
<h1><?= h($b['typ'] === 'gassi' ? 'Gassi-/Besuchstermin' : 'Betreuungsanfrage') ?> <?= status_etikett($b['status']) ?></h1>
<p class="klein"><?= h($b['id']) ?> · eingegangen am <?= h(datum_de(substr($b['erstellt'], 0, 10))) ?> um <?= h(substr($b['erstellt'], 11, 5)) ?> Uhr<?= !empty($b['anonymisiert']) ? ' · anonymisiert am ' . h(datum_de(substr($b['anonymisiert'], 0, 10))) : '' ?></p>

<?php if ($ueberschneidungen): ?>
  <div class="hinweis warnung">
    <strong>Am selben Tag / im selben Zeitraum:</strong>
    <ul>
    <?php foreach ($ueberschneidungen as $a): ?>
      <li><a href="?seite=buchung&amp;id=<?= h(rawurlencode($a['id'])) ?>"><?= h(buchung_zeitraum_text($a)) ?></a> – <?= h($a['tarifName']) ?>, <?= h($a['kunde']['name']) ?> (<?= h(STATUS[$a['status']]) ?>)</li>
    <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($gesperrteTage): ?>
  <div class="hinweis warnung"><strong>Achtung, im Zeitraum sind Tage gesperrt:</strong> <?= h(implode(', ', $gesperrteTage)) ?></div>
<?php endif; ?>

<div class="detail-raster">
  <section class="asc-card">
    <h2 class="h3">Termin &amp; Leistung</h2>
    <dl class="daten">
      <dt>Termin</dt><dd><?= h(buchung_zeitraum_text($b)) ?></dd>
      <dt>Tarif</dt><dd><?= h($b['tarifName'] ?? $b['tarifId']) ?></dd>
      <?php if (!empty($b['ortBetreuung'])): ?><dt>Ort</dt><dd><?= $b['ortBetreuung'] === 'bei-uns' ? 'bei uns' : 'beim Halter' ?></dd><?php endif; ?>
      <dt>Preis</dt><dd><strong><?= h(euro($b['preisGesamt'])) ?></strong><?= !empty($b['preisAngepasst']) ? ' (angepasst)' : '' ?><?php foreach ($b['preisPosten'] ?? [] as $p): ?><br><span class="klein"><?= h($p['text']) ?>: <?= h(euro($p['betrag'])) ?></span><?php endforeach; ?></dd>
    </dl>
  </section>
  <section class="asc-card">
    <h2 class="h3">Tier</h2>
    <dl class="daten">
      <dt>Name</dt><dd><?= h($t['name'] ?: '–') ?></dd>
      <dt>Art</dt><dd><?= h(TIERARTEN[$t['art']] ?? $t['art']) ?><?= ($b['anzahlTiere'] ?? 1) > 1 ? ', ' . (int) $b['anzahlTiere'] . ' Tiere' : '' ?></dd>
      <?php if ($t['rasse'] || $t['groesse']): ?><dt>Rasse/Größe</dt><dd><?= h(trim($t['rasse'] . ' ' . $t['groesse'])) ?></dd><?php endif; ?>
      <?php if ($t['hinweise']): ?><dt>Besonderheiten</dt><dd><?= h($t['hinweise']) ?></dd><?php endif; ?>
      <?php if (!empty($b['freitext'])): ?><dt>Hinweise</dt><dd><?= h($b['freitext']) ?></dd><?php endif; ?>
    </dl>
  </section>
  <section class="asc-card">
    <h2 class="h3">Kunde</h2>
    <dl class="daten">
      <dt>Name</dt><dd><?= h($k['name'] ?: '(anonymisiert)') ?></dd>
      <dt>Telefon</dt><dd><?php if ($k['telefon']): ?><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $k['telefon'])) ?>"><?= h($k['telefon']) ?></a><?php endif; ?></dd>
      <dt>E-Mail</dt><dd><?php if ($k['email']): ?><a href="mailto:<?= h($k['email']) ?>"><?= h($k['email']) ?></a><?php endif; ?></dd>
      <dt>Adresse</dt><dd><?= h(trim($k['strasse'] . ', ' . $k['ort'], ', ')) ?></dd>
      <dt>Datenschutz</dt><dd>bestätigt am <?= h(datum_de(substr($b['datenschutzOk']['zeit'] ?? '', 0, 10), false)) ?></dd>
    </dl>
  </section>
  <section class="asc-card">
    <h2 class="h3">Verlauf</h2>
    <ul class="klein">
    <?php foreach ($b['verlauf'] ?? [] as $eintrag): ?>
      <li><?= h(datum_de(substr($eintrag['zeit'], 0, 10), false)) ?> <?= h(substr($eintrag['zeit'], 11, 5)) ?> – <?= h(STATUS[$eintrag['status']] ?? $eintrag['status']) ?><?= $eintrag['notiz'] ? ': ' . h($eintrag['notiz']) : '' ?></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>

<h2>Status ändern</h2>
<form method="post" class="formular asc-card">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="status">
  <input type="hidden" name="id" value="<?= h($b['id']) ?>">
  <div class="feld">
    <label for="nachricht">Persönliche Nachricht an den Kunden <span class="optional">(optional, nur bei Bestätigung/Absage)</span></label>
    <textarea id="nachricht" name="nachricht" rows="2"></textarea>
  </div>
  <label class="auswahl"><input type="checkbox" name="mailen" value="1" checked> Kunden per E-Mail informieren (bei Bestätigung/Absage)</label>
  <div class="button-reihe">
    <?php if ($b['status'] !== 'bestaetigt'): ?><button class="asc-btn asc-btn--primary" name="status" value="bestaetigt">Bestätigen</button><?php endif; ?>
    <?php if ($b['status'] !== 'abgelehnt'): ?><button class="asc-btn asc-btn--ghost" name="status" value="abgelehnt">Ablehnen</button><?php endif; ?>
    <?php if ($b['status'] !== 'erledigt'): ?><button class="asc-btn asc-btn--ghost" name="status" value="erledigt">Als erledigt markieren</button><?php endif; ?>
    <?php if ($b['status'] !== 'offen'): ?><button class="asc-btn asc-btn--ghost" name="status" value="offen">Zurück auf offen</button><?php endif; ?>
  </div>
</form>

<h2>Preis &amp; Notiz</h2>
<form method="post" class="formular asc-card">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="buchung-bearbeiten">
  <input type="hidden" name="id" value="<?= h($b['id']) ?>">
  <div class="feld-reihe">
    <div class="feld">
      <label for="preisGesamt">Preis gesamt in € (z. B. Stammkunden-Rabatt)</label>
      <input type="text" inputmode="decimal" id="preisGesamt" name="preisGesamt" value="<?= h(number_format((float) $b['preisGesamt'], 2, ',', '')) ?>">
    </div>
    <div class="feld">
      <label for="adminNotiz">Interne Notiz (wer geht, Schlüssel …)</label>
      <textarea id="adminNotiz" name="adminNotiz" rows="2"><?= h($b['adminNotiz'] ?? '') ?></textarea>
    </div>
  </div>
  <div><button class="asc-btn asc-btn--ghost" type="submit">Speichern</button></div>
</form>
