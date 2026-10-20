<?php
/** Verfügbarkeit pflegen: Tage freigeben/sperren, Zeiträume, Standard-Zeitfenster. */
defined('ASC_ADMIN') || exit;

$v = verfuegbarkeit_laden();
$monat = preg_match('/^\d{4}-\d{2}$/', $_GET['monat'] ?? '') ? $_GET['monat'] : date('Y-m');
$ersterTag = $monat . '-01';
$letzterTag = (new DateTimeImmutable($ersterTag))->modify('last day of this month')->format('Y-m-d');
$vorher = (new DateTimeImmutable($ersterTag))->modify('-1 month')->format('Y-m');
$nachher = (new DateTimeImmutable($ersterTag))->modify('+1 month')->format('Y-m');
$gewaehlt = ist_datum($_GET['tag'] ?? null) ? $_GET['tag'] : null;
[$buchbarVon, $buchbarBis] = buchbarer_zeitraum($v);

// Buchungen je Tag (offen + bestätigt)
$proTag = [];
foreach ($buchungen as $b) {
    if (!in_array($b['status'], ['offen', 'bestaetigt'], true)) {
        continue;
    }
    if ($b['typ'] === 'gassi') {
        $proTag[$b['datum']][] = $b;
    } else {
        foreach (tage_im_bereich(max($b['von'], $ersterTag), min($b['bis'], $letzterTag)) as $d) {
            $proTag[$d][] = $b;
        }
    }
}
$manuell = [];
foreach ($v['tage'] as $t) {
    $manuell[$t['datum']] = $t;
}
$monatsnamen = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$wtNamen = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
?>
<h1>Verfügbarkeit</h1>
<p>Klicke auf einen Tag, um ihn freizugeben oder zu sperren. Öffentlich buchbar ist aktuell <strong><?= h(datum_de($buchbarVon)) ?></strong> bis <strong><?= h(datum_de($buchbarBis)) ?></strong>.</p>

<div class="kalender-kopf">
  <a class="asc-btn asc-btn--ghost asc-btn--small" href="?seite=kalender&amp;monat=<?= h($vorher) ?>" aria-label="Vorheriger Monat">‹</a>
  <h2 class="h3"><?= h($monatsnamen[(int) substr($monat, 5, 2)] . ' ' . substr($monat, 0, 4)) ?></h2>
  <a class="asc-btn asc-btn--ghost asc-btn--small" href="?seite=kalender&amp;monat=<?= h($nachher) ?>" aria-label="Nächster Monat">›</a>
</div>
<div class="admin-kalender">
  <?php foreach ($wtNamen as $wt): ?><div class="wt"><?= $wt ?></div><?php endforeach; ?>
  <?php for ($i = 1; $i < wochentag($ersterTag); $i++): ?><div class="leer"></div><?php endfor; ?>
  <?php foreach (tage_im_bereich($ersterTag, $letzterTag) as $d):
      $r = tag_regel($v, $d);
      $klassen = [$r['frei'] ? 'frei' : ($r['quelle'] ? 'gesperrt' : '')];
      if (isset($manuell[$d])) $klassen[] = 'manuell';
      if ($d === $gewaehlt) $klassen[] = 'gewaehlt';
      if ($d < heute()) $klassen[] = 'vergangen';
      $anzahl = count($proTag[$d] ?? []);
  ?>
    <a href="?seite=kalender&amp;monat=<?= h($monat) ?>&amp;tag=<?= h($d) ?>#tag" class="<?= h(implode(' ', array_filter($klassen))) ?>">
      <b><?= (int) substr($d, 8, 2) ?></b>
      <?php if ($r['frei']): ?><?= h(implode(' ', array_map(fn($z) => substr($z, 0, 2), $r['zeitfenster']))) ?> Uhr<br><?php elseif ($r['quelle']): ?>gesperrt<br><?php endif; ?>
      <?php if ($r['notiz']): ?><?= h(mb_strimwidth($r['notiz'], 0, 18, '…')) ?><br><?php endif; ?>
      <?php if ($anzahl): ?><strong><?= $anzahl ?> Buchung<?= $anzahl > 1 ? 'en' : '' ?></strong><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<ul class="kalender-legende">
  <li><span class="l-frei"></span>freigegeben (Uhrzeiten = Beginn der Zeitfenster)</li>
  <li><span class="l-gesperrt"></span>gesperrt</li>
  <li><span class="l-voll"></span>nicht freigegeben</li>
  <li><span class="l-manuell"></span>einzeln eingestellt</li>
</ul>

<?php if ($gewaehlt):
    $r = tag_regel($v, $gewaehlt);
    $eigen = $manuell[$gewaehlt] ?? null;
    $aktuelleFenster = $r['frei'] ? $r['zeitfenster'] : [];
    $belegt = belegte_zeitfenster($buchungen)[$gewaehlt] ?? [];
    $angebot = array_values(array_unique(array_merge($v['standardZeitfenster'], $aktuelleFenster)));
    sort($angebot);
?>
  <section class="asc-card abstand-oben" id="tag">
    <h2 class="h3"><?= h(datum_de($gewaehlt)) ?></h2>
    <p>Aktuell: <strong><?= $r['frei'] ? 'frei (' . h(implode(', ', $r['zeitfenster'])) . ')' : 'nicht buchbar' ?></strong>
      <?= $r['quelle'] === 'zeitraum' ? '– aus einem Zeitraum' : ($r['quelle'] === 'tag' ? '– einzeln eingestellt' : '') ?>
      <?= $r['notiz'] ? '· ' . h($r['notiz']) : '' ?></p>

    <?php if (!empty($proTag[$gewaehlt])): ?>
      <ul>
      <?php foreach ($proTag[$gewaehlt] as $b): ?>
        <li><a href="?seite=buchung&amp;id=<?= h(rawurlencode($b['id'])) ?>"><?= h(buchung_zeitraum_text($b)) ?></a> – <?= h($b['tarifName']) ?>, <?= h($b['kunde']['name']) ?> <?= status_etikett($b['status']) ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="aktion" value="tag">
      <input type="hidden" name="datum" value="<?= h($gewaehlt) ?>">
      <fieldset>
        <legend>Zeitfenster (max. <?= MAX_ZEITFENSTER_PRO_TAG ?>)</legend>
        <div class="auswahl-gruppe">
          <?php foreach ($angebot as $zf): ?>
            <label class="auswahl"><input type="checkbox" name="fenster[]" value="<?= h($zf) ?>" <?= in_array($zf, $aktuelleFenster, true) || (!$r['frei'] && in_array($zf, $v['standardZeitfenster'], true)) ? 'checked' : '' ?>> <?= h($zf) ?><?= isset($belegt[$zf]) ? ' (belegt)' : '' ?></label>
          <?php endforeach; ?>
        </div>
        <div class="feld">
          <label for="fensterNeu">Weiteres Zeitfenster <span class="optional">(z. B. 10:00-11:00)</span></label>
          <input type="text" id="fensterNeu" name="fensterNeu[]" pattern="\d{2}:\d{2}-\d{2}:\d{2}" placeholder="10:00-11:00">
        </div>
        <div class="feld">
          <label for="notiz">Notiz <span class="optional">(z. B. Klassenfahrt)</span></label>
          <input type="text" id="notiz" name="notiz" maxlength="120" value="<?= h($eigen['notiz'] ?? '') ?>">
        </div>
      </fieldset>
      <div class="button-reihe">
        <button class="asc-btn asc-btn--primary" name="modus" value="freigeben">Freigeben</button>
        <button class="asc-btn asc-btn--ghost" name="modus" value="sperren">Sperren</button>
        <?php if ($eigen): ?><button class="asc-btn asc-btn--ghost" name="modus" value="zuruecksetzen">Einzeleinstellung entfernen</button><?php endif; ?>
      </div>
    </form>
  </section>
<?php endif; ?>

<h2 id="zeitraeume">Zeiträume</h2>
<p>Zeiträume geben Tage pauschal frei (z. B. „in der Schulzeit Mo, Mi, Fr“) oder sperren sie (z. B. Urlaub, Prüfungszeit). Sie werden der Reihe nach angewendet; einzeln eingestellte Tage haben immer Vorrang.</p>
<?php if ($v['zeitraeume']): ?>
<div class="tabelle-rahmen">
  <table>
    <thead><tr><th>Zeitraum</th><th>Aktion</th><th>Wochentage</th><th>Zeitfenster</th><th>Notiz</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($v['zeitraeume'] as $i => $z): ?>
      <tr>
        <td><?= h(datum_de($z['von'], false)) ?> – <?= h(datum_de($z['bis'], false)) ?></td>
        <td><?= ($z['aktion'] ?? '') === 'sperren' ? '<span class="asc-badge asc-badge--warning">sperren</span>' : '<span class="asc-badge asc-badge--success">freigeben</span>' ?></td>
        <td><?= !empty($z['wochentage']) ? h(implode(', ', array_map(fn($w) => $wtNamen[(int) $w] ?? $w, $z['wochentage']))) : 'alle' ?></td>
        <td><?= ($z['aktion'] ?? '') === 'sperren' ? '–' : h(implode(', ', $z['zeitfenster'] ?? []) ?: 'Standard') ?></td>
        <td><?= h($z['notiz'] ?? '') ?></td>
        <td>
          <form method="post" class="inline-form" onsubmit="return confirm('Zeitraum wirklich löschen?')">
            <?= csrf_feld() ?>
            <input type="hidden" name="aktion" value="zeitraum-loeschen">
            <input type="hidden" name="index" value="<?= (int) $i ?>">
            <button class="asc-btn asc-btn--ghost asc-btn--small" type="submit">Löschen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<form method="post" class="formular asc-card abstand-oben">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="zeitraum-neu">
  <h3>Neuer Zeitraum</h3>
  <div class="feld-reihe">
    <div class="feld"><label for="z-von">Von</label><input type="date" id="z-von" name="von" required></div>
    <div class="feld"><label for="z-bis">Bis</label><input type="date" id="z-bis" name="bis" required></div>
  </div>
  <div class="feld">
    <span class="label">Aktion</span>
    <div class="auswahl-gruppe">
      <label class="auswahl"><input type="radio" name="art" value="freigeben" checked> freigeben</label>
      <label class="auswahl"><input type="radio" name="art" value="sperren"> sperren</label>
    </div>
  </div>
  <div class="feld">
    <span class="label">Nur an diesen Wochentagen <span class="optional">(keiner = alle Tage)</span></span>
    <div class="auswahl-gruppe">
      <?php foreach ($wtNamen as $n => $wt): ?>
        <label class="auswahl"><input type="checkbox" name="wochentage[]" value="<?= $n ?>"> <?= $wt ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="feld">
    <span class="label">Zeitfenster beim Freigeben <span class="optional">(leer = Standard: <?= h(implode(', ', $v['standardZeitfenster'])) ?>)</span></span>
    <div class="feld-reihe">
      <input type="text" name="fensterNeu[]" placeholder="14:00-15:00" aria-label="Zeitfenster 1">
      <input type="text" name="fensterNeu[]" placeholder="15:00-16:00" aria-label="Zeitfenster 2">
      <input type="text" name="fensterNeu[]" placeholder="17:00-18:00" aria-label="Zeitfenster 3">
    </div>
  </div>
  <div class="feld"><label for="z-notiz">Notiz</label><input type="text" id="z-notiz" name="notiz" maxlength="120" placeholder="z. B. Herbstferien, Prüfungswoche"></div>
  <div><button class="asc-btn asc-btn--primary" type="submit">Zeitraum hinzufügen</button></div>
</form>

<h2 id="einstellungen">Einstellungen</h2>
<form method="post" class="formular asc-card">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="einstellungen">
  <div class="feld">
    <span class="label">Standard-Zeitfenster</span>
    <div class="feld-reihe">
      <?php for ($i = 0; $i < MAX_ZEITFENSTER_PRO_TAG; $i++): ?>
        <input type="text" name="standardZeitfenster[]" value="<?= h($v['standardZeitfenster'][$i] ?? '') ?>" placeholder="14:00-15:00" aria-label="Standard-Zeitfenster <?= $i + 1 ?>">
      <?php endfor; ?>
    </div>
  </div>
  <div class="feld-reihe">
    <div class="feld"><label for="vorlauf">Frühestens buchbar in … Tagen</label><input type="number" id="vorlauf" name="vorlaufTage" min="0" max="14" value="<?= (int) $v['vorlaufTage'] ?>"></div>
    <div class="feld"><label for="wochen">Kalender reicht … Wochen voraus</label><input type="number" id="wochen" name="wochenVoraus" min="1" max="26" value="<?= (int) $v['wochenVoraus'] ?>"></div>
  </div>
  <div><button class="asc-btn asc-btn--ghost" type="submit">Speichern</button></div>
</form>
