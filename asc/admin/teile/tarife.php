<?php
/** Tarife bearbeiten: Name, Beschreibung, Einheit, Preis, Staffel, Zuschläge, aktiv. */
defined('ASC_ADMIN') || exit;

$tarife = tarife_laden();
$regeln = ['pro-weiteres-tier' => 'je weiteres Tier', 'sonn-feiertag' => 'je Sonn-/Feiertag', 'optional' => 'wählbar'];
?>
<h1>Tarife</h1>
<p>Änderungen gelten sofort für die Preisseite und die Formulare. Bereits eingegangene Buchungen behalten ihren Preis. Neue Tarife oder Zuschläge bitte direkt in <code>tarife.json</code> anlegen.</p>

<form method="post" class="formular">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="tarife">
  <?php foreach ($tarife as $t): $p = 'tarif[' . h($t['id']) . ']'; $fid = 't-' . h($t['id']); ?>
    <fieldset>
      <legend><?= h($t['name']) ?> <span class="asc-badge <?= $t['art'] === 'direkt' ? 'asc-badge--direkt' : 'asc-badge--anfrage' ?>"><?= $t['art'] === 'direkt' ? 'Direkt buchbar' : 'Auf Anfrage' ?></span></legend>
      <div class="auswahl-gruppe">
        <label class="auswahl"><input type="checkbox" name="<?= $p ?>[aktiv]" value="1" <?= !empty($t['aktiv']) ? 'checked' : '' ?>> aktiv (auf der Seite sichtbar)</label>
        <label class="auswahl"><input type="checkbox" name="<?= $p ?>[empfohlen]" value="1" <?= !empty($t['empfohlen']) ? 'checked' : '' ?>> empfohlen („Beliebt“)</label>
      </div>
      <div class="feld-reihe">
        <div class="feld"><label for="<?= $fid ?>-name">Name</label><input type="text" id="<?= $fid ?>-name" name="<?= $p ?>[name]" value="<?= h($t['name']) ?>" required maxlength="60"></div>
        <div class="feld"><label for="<?= $fid ?>-einheit">Einheit</label><input type="text" id="<?= $fid ?>-einheit" name="<?= $p ?>[einheit]" value="<?= h($t['einheit'] ?? '') ?>" maxlength="30"></div>
      </div>
      <div class="feld"><label for="<?= $fid ?>-beschreibung">Beschreibung</label><input type="text" id="<?= $fid ?>-beschreibung" name="<?= $p ?>[beschreibung]" value="<?= h($t['beschreibung'] ?? '') ?>" maxlength="200"></div>
      <div class="feld-reihe">
        <div class="feld">
          <label for="<?= $fid ?>-preis">Preis in € <span class="optional">(<?= h(['pro-termin' => 'pro Termin', 'pro-tag' => 'pro Tag', 'pro-wochenende' => 'pro Wochenende'][$t['abrechnung'] ?? 'pro-termin']) ?>)</span></label>
          <input type="text" inputmode="decimal" id="<?= $fid ?>-preis" name="<?= $p ?>[preis]" value="<?= h(str_replace('.', ',', (string) $t['preis'])) ?>">
        </div>
        <?php if (isset($t['staffel'])): ?>
        <div class="feld-reihe">
          <div class="feld"><label for="<?= $fid ?>-sab">Staffel ab Tagen</label><input type="number" id="<?= $fid ?>-sab" name="<?= $p ?>[staffelAb]" min="2" value="<?= (int) $t['staffel']['abTagen'] ?>"></div>
          <div class="feld"><label for="<?= $fid ?>-sp">Staffelpreis/Tag €</label><input type="text" inputmode="decimal" id="<?= $fid ?>-sp" name="<?= $p ?>[staffelPreis]" value="<?= h(str_replace('.', ',', (string) $t['staffel']['preis'])) ?>"></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($t['zuschlaege'])): ?>
        <div class="feld-reihe">
          <?php foreach ($t['zuschlaege'] as $z): ?>
            <div class="feld">
              <label for="<?= $fid ?>-z-<?= h($z['id']) ?>">Zuschlag: <?= h($z['name']) ?> <span class="optional">(<?= h($regeln[$z['regel'] ?? 'optional'] ?? '') ?>)</span></label>
              <input type="text" inputmode="decimal" id="<?= $fid ?>-z-<?= h($z['id']) ?>" name="<?= $p ?>[zuschlag][<?= h($z['id']) ?>]" value="<?= h(str_replace('.', ',', (string) $z['preis'])) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>
  <div><button class="asc-btn asc-btn--primary" type="submit">Alle Tarife speichern</button></div>
</form>
